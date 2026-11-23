<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ResolvesDrift;
use App\Contracts\ScansWholeRepo;
use App\Data\Check;
use App\Sync\Action;
use App\Sync\Change;
use App\Sync\Decision;
use App\Sync\DriftItem;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\table;

final class InitCommand extends Command
{
    use ResolvesDrift;

    protected $signature = 'init
        {--repo= : Path to your dotfiles directory}
        {--checks= : Checks to write a manifest for, comma-separated (such as brew,npm)}
        {--dry-run : Show the files it would write, and change nothing}';

    protected $description = 'Write a first manifest for each check that has none, from what this Mac has installed';

    /**
     * Scans every installed tool whose check found no manifest, then writes what's installed
     * into a new file at the repo's root, named after the check's first pattern. A check that
     * already has a manifest is left to sync.
     *
     * @return int failure when a file couldn't be planned or written
     *
     * @throws InvalidArgumentException when --checks names a check that doesn't exist or can't run
     */
    public function handle(): int
    {
        $checks = $this->allChecks();
        $bare = array_values(array_filter(
            $this->option('checks') === null ? $checks : $this->checksFromOption($checks),
            fn (Check $check) => $check->available && $check->manifests === [] && ! $check->target instanceof ScansWholeRepo,
        ));

        if ($bare === []) {
            $this->info('Every check that can run here already has a manifest.');

            return self::SUCCESS;
        }

        $installed = array_filter($this->itemsOf($this->scanEach($bare)), fn (DriftItem $item) => isset($this->actionsFor($item)[Action::AddToManifest->value]));
        $changes = $this->planned($this->withManifests(array_map(
            fn (DriftItem $item) => new Decision($item, Action::AddToManifest),
            array_values($installed),
        )));

        if ($changes === []) {
            $this->info('Nothing installed to write down.');

            return $this->failureReport();
        }

        $this->showFiles($changes);

        if ($this->option('dry-run') || ! $this->input->isInteractive()) {
            $this->line($this->option('dry-run') ? 'Dry run. Nothing was written.' : 'Run it in a terminal to write them.');

            return $this->failureReport();
        }

        if (! confirm('Write these files?', default: true)) {
            return $this->failureReport();
        }

        $uncommitted = $this->uncommittedFiles();
        $applied = $this->apply($changes);
        $this->showApplied(count($applied), count($changes));

        if ($applied !== []) {
            $this->offerCommit($applied, $uncommitted);
            $this->line('Run dotsnitch sync to compare them with this Mac from now on.');
        }

        return $this->failureReport();
    }

    /**
     * One row per new file, with how many entries go in it.
     *
     * @param  list<Change>  $changes
     */
    private function showFiles(array $changes): void
    {
        $entries = [];

        foreach ($changes as $change) {
            $file = substr($change->decision->manifests[0], strlen($this->root) + 1);
            $label = $this->checksByKey[$change->decision->item->check]->target->label();
            $entries[$label][$file] = ($entries[$label][$file] ?? 0) + 1;
        }

        $rows = [];

        foreach ($entries as $label => $files) {
            foreach ($files as $file => $count) {
                $rows[] = [$label, $file, (string) $count];
            }
        }

        table(['Check', 'New file', 'Entries'], $rows);
    }
}
