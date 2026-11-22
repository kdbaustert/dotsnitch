<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\JsonFile;
use App\Sync\Step;
use App\Sync\SyncLog;
use App\Sync\Undo;
use ErrorException;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function Laravel\Prompts\confirm;

final class UndoCommand extends Command
{
    protected $signature = 'undo
        {record? : The file sync said it recorded its changes in; the last sync when left out}
        {--packages : Also uninstall what the sync installed, and install what it uninstalled}
        {--dry-run : List what would be put back, and change nothing}';

    protected $description = 'Put back the files and links a sync changed';

    /**
     * Lists what it would put back, asks, then does it last change first. Packages a sync
     * installed or removed stay as they are without --packages, and a commit it made always does.
     *
     * @return int failure when the record can't be read or a change couldn't be put back
     */
    public function handle(SyncLog $log, Undo $undo): int
    {
        $path = $this->argument('record') ?? $log->saved()[0] ?? null;

        if ($path === null) {
            $this->line('No sync has changed anything yet.');

            return self::SUCCESS;
        }

        try {
            $record = JsonFile::read($path) ?? throw new RuntimeException("No sync record at {$path}");
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (isset($record['undone_at'])) {
            $this->line("The sync of {$record['started_at']} was already undone.");

            return self::SUCCESS;
        }

        $steps = $undo->steps($record['steps']);

        if ($this->option('packages')) {
            $steps = [...$steps, ...$undo->packageSteps($record['steps'])];
            $this->showLeftAlone($undo->oneWayCommands($record['steps']), $record['commit']);
        } else {
            $this->showLeftAlone($undo->commands($record['steps']), $record['commit']);
        }

        if ($steps === []) {
            $this->line("The sync of {$record['started_at']} changed nothing that can be put back.");

            return self::SUCCESS;
        }

        $this->line("To undo the sync of {$record['started_at']}, these will run, in order:");

        foreach ($steps as $step) {
            $this->line('  '.OutputFormatter::escape($step->description));
        }

        // Without a terminal the confirm would answer itself.
        if ($this->option('dry-run') || ! $this->input->isInteractive() || ! confirm('Undo these changes?', default: true)) {
            return self::SUCCESS;
        }

        $failures = $this->putBack($steps);

        // A run that put nothing back can be tried again.
        if (count($failures) < count($steps)) {
            JsonFile::write($path, [...$record, 'undone_at' => date(DATE_ATOM)]);
        }

        $this->line('Put back '.(count($steps) - count($failures)).' of '.count($steps).'.');

        foreach ($failures as $failure) {
            $this->line('  Left alone: '.OutputFormatter::escape($failure));
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $commands
     * @param  ?array{repo: string, message: string}  $commit
     */
    private function showLeftAlone(array $commands, ?array $commit): void
    {
        if ($commands !== []) {
            $this->line('These ran and stay as they are:');

            foreach ($commands as $command) {
                $this->line('  '.OutputFormatter::escape($command));
            }

            if (! $this->option('packages')) {
                $this->line('Add --packages to take back the installs and uninstalls among them.');
            }
        }

        if ($commit !== null) {
            $this->line("The sync committed \"{$commit['message']}\" in {$commit['repo']}. Take that back with git revert.");
        }
    }

    /**
     * A step that fails doesn't stop the ones after it.
     *
     * @param  list<Step>  $steps
     * @return list<string> one line per step that failed, with why
     */
    private function putBack(array $steps): array
    {
        $failures = [];

        foreach ($steps as $step) {
            try {
                ($step->run)();
            } catch (RuntimeException|ErrorException $e) {
                $failures[] = "{$step->description}: {$e->getMessage()}";
            }
        }

        return $failures;
    }
}
