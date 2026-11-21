<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ResolvesDrift;
use App\Data\Check;
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

    /**
     * Asks which checks to run, scans each one with a live status, saves and prints the drift,
     * then asks what to do about it.
     *
     * @return int failure when any check couldn't finish (the others still run), when a
     *             report-only run found drift, or when a change failed
     *
     * @throws InvalidArgumentException when --checks names a check that doesn't exist or can't run
     */
    public function handle(UserSettings $settings): int
    {
        $checks = $this->allChecks();
        $selected = $this->option('checks') === null
            ? $this->askForChecks($checks, $settings)
            : $this->checksFromOption($checks);

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
