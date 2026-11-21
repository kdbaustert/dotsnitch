<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Contracts\SyncTarget;
use App\Data\Check;
use App\Support\AmbiguousHomebrew;
use App\Support\DotfilesRepo;
use App\Support\Machine;
use App\Support\Manifests;
use App\Support\PathResolver;
use App\Support\UserSettings;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\DriftReport;
use App\Sync\IgnoreList;
use App\Sync\ReportStore;
use App\Sync\SavedScan;
use Laravel\Prompts\Support\Logger;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException;

use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\task;

/**
 * Finds the repo and the checks, scans them, saves the result and prints the report. Shared by
 * sync and status.
 *
 * @mixin Command
 */
trait ScansChecks
{
    use ChoosesHomebrew;

    /** Where the last picked checks are saved, so the next run starts from them. */
    private const SELECTION_SETTING = 'checks';

    /** @var array<string, Check> the checks that ran, by key */
    private array $checksByKey = [];

    private string $root = '';

    private string $hostname = '';

    /**
     * Resolves the repo, this Mac and its Homebrew, then pairs every registered check with the
     * manifests it reads here. Without a terminal, manifest choices fall back to defaults.
     *
     * @return list<Check>
     */
    private function allChecks(): array
    {
        $paths = $this->laravel->make(PathResolver::class);
        $machine = $this->laravel->make(Machine::class);
        $manifests = $this->laravel->make(Manifests::class);

        // Prompts can't run under the spinner, so the dotfiles prompt comes before it and the
        // manifest and Homebrew prompts after.
        $this->root = $this->laravel->make(DotfilesRepo::class)->resolve($this->option('repo'));
        $this->hostname = spin(function () use ($machine, $paths) {
            $this->detectEnvironment($paths);

            return $machine->hostname();
        }, 'Detecting machine and locating manifests…');

        $this->resolveHomebrewPrefix($paths);

        /** @var list<SyncTarget> $targets */
        $targets = [...$this->laravel->tagged('sync-targets')];
        $chosen = ($this->input->isInteractive() ? $manifests : $manifests->withDefaults())
            ->only(array_map(fn (SyncTarget $target) => $target->key(), $targets))
            ->chosen($this->root, $this->hostname);

        return array_map(fn (SyncTarget $target) => new Check($target, $chosen[$target->key()], $target->isAvailable()), $targets);
    }

    /**
     * Builds the login environment while the spinner is up. When both Homebrews exist and none is
     * chosen yet, this leaves it for the prompt after the spinner.
     */
    private function detectEnvironment(PathResolver $paths): void
    {
        try {
            $paths->homebrewPrefix();
        } catch (AmbiguousHomebrew) {
            return;
        }

        $paths->homeEnvironment();
    }

    /**
     * The checks sync ran last time that are still installed, or every ready check before the
     * first pick.
     *
     * @param  array<string, Check>  $byKey
     * @return list<string> check keys
     */
    private function defaultCheckKeys(array $byKey, UserSettings $settings): array
    {
        $saved = $settings->get(self::SELECTION_SETTING);

        return is_array($saved)
            ? array_values(array_filter($saved, fn ($key) => ($byKey[$key] ?? null)?->available))
            : array_keys(array_filter($byKey, fn (Check $check) => $check->isReady()));
    }

    /**
     * @param  list<Check>  $checks
     * @return list<Check> in registration order, not the order typed
     *
     * @throws InvalidArgumentException for an unknown key, or a check whose tool isn't installed
     */
    private function checksFromOption(array $checks): array
    {
        $keys = array_filter(array_map(trim(...), explode(',', $this->option('checks'))));
        $known = array_map(fn (Check $check) => $check->key(), $checks);
        $unknown = array_diff($keys, $known);

        if ($keys === [] || $unknown !== []) {
            throw new InvalidArgumentException('Unknown check "'.implode('", "', $unknown).'" in --checks. Checks: '.implode(', ', $known).'.');
        }

        $selected = array_values(array_filter($checks, fn (Check $check) => in_array($check->key(), $keys, true)));

        foreach ($selected as $check) {
            if (! $check->available) {
                throw new InvalidArgumentException("{$check->target->label()} isn't installed on this Mac.");
            }
        }

        return $selected;
    }

    /**
     * Scans each check in turn and remembers them for report() and resolving.
     *
     * @param  list<Check>  $selected
     * @return array<string, DriftReport|RuntimeException> keyed by check
     */
    private function scanEach(array $selected): array
    {
        $reports = [];

        foreach ($selected as $check) {
            $this->checksByKey[$check->key()] = $check;
            $reports[$check->key()] = $this->scan($check);
        }

        return $reports;
    }

    /**
     * Runs one check under a task status that shows its log lines while it works and keeps the
     * outcome on screen after. Without a terminal only the outcome prints, so report() repeats
     * any error.
     *
     * @return DriftReport|RuntimeException the error when the check couldn't finish
     */
    private function scan(Check $check): DriftReport|RuntimeException
    {
        $ignores = $this->laravel->make(IgnoreList::class);

        return task($check->target->label(), function (Logger $logger) use ($check, $ignores) {
            try {
                $report = $ignores->apply($check->target->scan(
                    array_map(fn (string $file) => "{$this->root}/{$file}", $check->manifests),
                    $logger->line(...),
                ));
            } catch (RuntimeException $e) {
                $logger->error("Check failed: {$e->getMessage()}");

                return $e;
            }

            $report->isInSync()
                ? $logger->success($this->statusOf($report))
                : $logger->warning(count($report->items).' out of sync'.$this->ignoredSuffix($report->ignored));

            return $report;
        }, keepSummary: true);
    }

    /**
     * Saves the scan, so the next run and shell prompts can see what's new.
     *
     * @param  array<string, DriftReport|RuntimeException>  $reports  keyed by check
     */
    private function saveScan(array $reports): SavedScan
    {
        return $this->laravel->make(ReportStore::class)->save($this->root, $reports);
    }

    /**
     * One summary row per check, a count line, then every drift item, with the new ones marked.
     * status --new lists only the new ones.
     *
     * @param  array<string, DriftReport|RuntimeException>  $reports  keyed by check
     * @return int failure when any check couldn't finish
     */
    private function report(array $reports, SavedScan $saved): int
    {
        table(['Check', 'Status'], array_map(
            fn (Check $check) => [$check->target->label(), $this->statusOf($reports[$check->key()])],
            array_values($this->checksByKey),
        ));

        $finished = array_filter($reports, fn ($report) => $report instanceof DriftReport);
        $items = $this->itemsOf($reports);

        if ($items !== []) {
            $this->line($this->driftCount(count($items), $saved));
            $listed = $this->listsOnlyNew() ? array_values(array_filter($items, $saved->isNew(...))) : $items;

            if ($listed !== []) {
                table(['Check', 'Drift', 'Package', 'Detail'], array_map(fn (DriftItem $item) => [
                    $this->checksByKey[$item->check]->target->label(),
                    $item->kind->value.($saved->isNew($item) ? ' (new)' : ''),
                    $item->subject->key(),
                    $item->detail ?? '',
                ], $listed));
            }
        }

        $ignored = array_sum(array_map(fn (DriftReport $report) => $report->ignored, $finished));

        if ($ignored > 0) {
            $this->line("{$ignored} ignored on this Mac. See them with dotsnitch config --ignored.");
        }

        if (count($finished) < count($reports)) {
            return Command::FAILURE;
        }

        if ($items === []) {
            $this->info('Everything is in sync.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, DriftReport|RuntimeException>  $reports
     * @return list<DriftItem> from the checks that finished
     */
    private function itemsOf(array $reports): array
    {
        $finished = array_filter($reports, fn ($report) => $report instanceof DriftReport);

        return array_merge(...array_map(fn (DriftReport $report) => $report->items, array_values($finished)));
    }

    /**
     * Such as "9 out of sync, 2 new since Nov 20, 09:00", or just "9 out of sync" on a first scan.
     */
    private function driftCount(int $count, SavedScan $saved): string
    {
        return $saved->since === null
            ? "{$count} out of sync"
            : "{$count} out of sync, {$saved->newCount()} new since {$saved->since->format('M j, H:i')}";
    }

    /**
     * Only status has --new.
     */
    private function listsOnlyNew(): bool
    {
        return $this->input->hasOption('new') && $this->option('new');
    }

    /**
     * Such as "In sync", "In sync (2 ignored)", "1 missing, 8 untracked" or "Failed: brew info failed: ...".
     */
    private function statusOf(DriftReport|RuntimeException $report): string
    {
        if ($report instanceof RuntimeException) {
            return "Failed: {$report->getMessage()}";
        }

        if ($report->isInSync()) {
            return 'In sync'.$this->ignoredSuffix($report->ignored);
        }

        $counts = [];

        foreach (DriftKind::cases() as $kind) {
            $count = count($report->itemsOfKind($kind));

            if ($count > 0) {
                $counts[] = "{$count} {$kind->value}";
            }
        }

        return implode(', ', $counts).$this->ignoredSuffix($report->ignored);
    }

    /**
     * @return string such as " (2 ignored)", or empty when nothing was
     */
    private function ignoredSuffix(int $ignored): string
    {
        return $ignored > 0 ? " ({$ignored} ignored)" : '';
    }
}
