<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\DotfilesRepo;
use App\Support\LaunchAgent;
use DateTimeImmutable;
use InvalidArgumentException;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

final class ScheduleCommand extends Command
{
    /** How much of the log the status view shows. */
    private const LOG_LINES = 5;

    private const DEFAULT_AT = '09:00';

    protected $signature = 'schedule
        {--install : Run status --notify every day, replacing any earlier schedule}
        {--uninstall : Stop the daily run}
        {--status : Show the daily run (the default)}
        {--at= : With --install, the time to run, 24-hour (default 09:00)}
        {--repo= : With --install, the dotfiles directory the daily run checks}';

    protected $description = 'Run dotsnitch status every day and get a notification when something new is out of sync';

    /**
     * @throws InvalidArgumentException when more than one action is passed, --at or --repo comes
     *                                  without --install, or --at isn't a time
     */
    public function handle(LaunchAgent $agent, DotfilesRepo $repo): int
    {
        $actions = array_keys(array_filter([
            'install' => $this->option('install'),
            'uninstall' => $this->option('uninstall'),
            'status' => $this->option('status'),
        ]));
        $action = $actions[0] ?? 'status';

        if (count($actions) > 1) {
            throw new InvalidArgumentException('Pick one of --install, --uninstall and --status.');
        }

        if ($action !== 'install' && ($this->option('at') !== null || $this->option('repo') !== null)) {
            throw new InvalidArgumentException('--at and --repo only go with --install.');
        }

        return match ($action) {
            'install' => $this->install($agent, $repo),
            'uninstall' => $this->uninstall($agent),
            default => $this->showStatus($agent),
        };
    }

    /**
     * The repo is fixed now, since launchd's run can't ask and has none of the shell's state.
     */
    private function install(LaunchAgent $agent, DotfilesRepo $repo): int
    {
        $at = $this->option('at') ?? self::DEFAULT_AT;
        $agent->install($at, $repo->resolve($this->option('repo')));

        info("dotsnitch status will run every day at {$at} and notify you about anything new.");
        $this->line("Output goes to {$agent->logPath()}.");

        return self::SUCCESS;
    }

    private function uninstall(LaunchAgent $agent): int
    {
        $this->line($agent->uninstall() ? 'The daily run is off.' : "The daily run wasn't set up.");

        return self::SUCCESS;
    }

    /**
     * When it runs, whether launchd has it loaded, what it runs, and the end of its log.
     */
    private function showStatus(LaunchAgent $agent): int
    {
        try {
            $installed = $agent->installed();
        } catch (RuntimeException $e) {
            error($e->getMessage());
            $this->line('Write it again with dotsnitch schedule --install.');

            return self::FAILURE;
        }

        if ($installed === null) {
            $this->line("The daily run isn't set up. Turn it on with dotsnitch schedule --install.");

            return self::SUCCESS;
        }

        $at = sprintf('%02d:%02d', $installed['hour'], $installed['minute']);
        $lastExit = $agent->lastExit();

        $this->line("Runs every day at {$at}, next ".$this->nextRun($installed['hour'], $installed['minute']).'.');
        $this->line($lastExit === null
            ? 'launchd: not loaded. Load it again with dotsnitch schedule --install.'
            : "launchd: loaded, last exit code {$lastExit}");
        $this->line('Command: '.implode(' ', $installed['arguments']));

        $program = $installed['arguments'][0] ?? '';

        if (! is_file($program)) {
            warning("{$program} no longer exists. Run dotsnitch schedule --install from the dotsnitch you use now.");
        }

        $log = $agent->logTail(self::LOG_LINES);

        if ($log !== []) {
            $this->line("Last lines of {$agent->logPath()}:");

            foreach ($log as $line) {
                $this->line($line);
            }
        }

        return self::SUCCESS;
    }

    /**
     * "today at 09:00" until that time passes, then "tomorrow at 09:00". A Mac asleep at that
     * time runs it on wake instead.
     */
    private function nextRun(int $hour, int $minute): string
    {
        $now = new DateTimeImmutable;
        $today = $now->setTime($hour, $minute);

        return ($today > $now ? 'today' : 'tomorrow').' at '.$today->format('H:i');
    }
}
