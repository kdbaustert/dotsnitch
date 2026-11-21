<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ResolvesDrift;
use App\Data\Check;
use App\Support\StatusLock;
use App\Support\UserSettings;
use App\Sync\DriftReport;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;

use function Laravel\Prompts\multiselect;

final class SyncCommand extends Command
{
    use ResolvesDrift;

    protected $signature = 'sync
        {--repo= : Path to your dotfiles directory}
        {--checks= : Checks to run, comma-separated (such as brew,npm); skips the prompt}
        {--dry-run : Show the plan and the exact commands, and change nothing}';

    protected $description = 'Compare your dotfiles with this Mac and resolve what is out of sync';

    /** Long enough for a background refresh to finish its scan. */
    private const LOCK_WAIT_SECONDS = 120;

    /**
     * Asks which checks to run, scans each one with a live status, saves and prints the drift,
     * then asks what to do about it. Holds the status lock from the scan on, so a status run
     * can't save or post a half-done sync.
     *
     * @return int failure when any check couldn't finish (the others still run), when a
     *             report-only run found drift, when a change failed, or when a status run
     *             kept the lock
     *
     * @throws InvalidArgumentException when --checks names a check that doesn't exist or can't run
     */
    public function handle(UserSettings $settings, StatusLock $lock): int
    {
        $checks = $this->allChecks();
        $selected = $this->option('checks') === null
            ? $this->askForChecks($checks, $settings)
            : $this->checksFromOption($checks);

        if (! $this->waitForLock($lock)) {
            $this->error('A dotsnitch status run is still scanning. Try again in a minute.');

            return self::FAILURE;
        }

        $reports = $this->scanEach($selected);
        $scanned = $this->report($reports, $this->saveScan($reports));
        $drifted = array_filter($reports, fn ($report) => $report instanceof DriftReport && ! $report->isInSync());

        if ($drifted === []) {
            return $scanned;
        }

        $resolved = $this->resolve($reports);

        return $scanned === self::SUCCESS ? $resolved : $scanned;
    }

    /**
     * Takes the lock, waiting out a status run that's scanning and saying so.
     *
     * @return bool false when the status run still holds it after LOCK_WAIT_SECONDS
     */
    private function waitForLock(StatusLock $lock): bool
    {
        if ($lock->acquire()) {
            return true;
        }

        $this->line('Waiting for the dotsnitch status run to finish.');

        return $lock->acquire(self::LOCK_WAIT_SECONDS);
    }

    /**
     * Starts from the last selection, or from every ready check the first time, and saves the answer.
     *
     * @param  list<Check>  $checks
     * @return list<Check>
     */
    private function askForChecks(array $checks, UserSettings $settings): array
    {
        $byKey = array_column(array_map(fn (Check $check) => [$check->key(), $check], $checks), 1, 0);

        $keys = multiselect(
            'Which checks should run?',
            array_map(fn (Check $check) => $check->choiceLabel(), $byKey),
            $this->defaultCheckKeys($byKey, $settings),
            scroll: max(count($byKey), 5),
            required: 'Pick at least one check.',
            validate: function (array $keys) use ($byKey) {
                $unavailable = array_filter($keys, fn (string $key) => ! $byKey[$key]->available);

                return $unavailable === [] ? null : $byKey[reset($unavailable)]->target->label()." isn't installed on this Mac.";
            },
        );

        // Without a terminal the default comes straight back, and that isn't a choice worth keeping.
        if ($this->input->isInteractive()) {
            $settings->set(self::SELECTION_SETTING, $keys);
        }

        return array_values(array_intersect_key($byKey, array_flip($keys)));
    }
}
