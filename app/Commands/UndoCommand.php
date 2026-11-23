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
use TypeError;

use function Laravel\Prompts\confirm;

final class UndoCommand extends Command
{
    protected $signature = 'undo
        {record? : The file sync said it recorded its changes in; the last sync when left out}
        {--packages : Also take back the commands that can be: an install, an uninstall, a macOS default that had no value}
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
            // Interpolated here so an array in started_at fails inside the try.
            $startedAt = "{$record['started_at']}";
            $undone = $record['undone'] ?? [];
            $steps = $undo->steps($record['steps'], $undone);
            $packageSteps = $undo->packageSteps($record['steps'], $undone);

            if ($this->option('packages')) {
                $steps = [...$steps, ...$packageSteps];
                $this->showLeftAlone($undo->oneWayCommands($record['steps']), $record['commit']);
            } else {
                $this->showLeftAlone($undo->commands($record['steps'], $undone), $record['commit'], $packageSteps === [] ? null : 'Add --packages to take back the ones that can be.');
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ErrorException|TypeError) {
            // A record edited by hand can be missing a key or hold the wrong type.
            $this->error("{$path} isn't a sync record dotsnitch can read.");

            return self::FAILURE;
        }

        if ($steps === []) {
            $this->line($undone === [] ? "The sync of {$startedAt} changed nothing that can be put back." : "The sync of {$startedAt} was already undone.");

            return self::SUCCESS;
        }

        $this->line("To undo the sync of {$startedAt}, these will run, in order:");

        foreach ($steps as $step) {
            $this->line('  '.OutputFormatter::escape($step->description));
        }

        // Without a terminal the confirm would answer itself.
        if ($this->option('dry-run') || ! $this->input->isInteractive() || ! confirm('Undo these changes?', default: true)) {
            return self::SUCCESS;
        }

        $failures = $this->putBack($steps);
        $putBack = array_keys(array_diff_key($steps, $failures));

        // Only what was put back is saved, so a step that failed is tried again next time.
        if ($putBack !== []) {
            JsonFile::write($path, [...$record, 'undone' => [...$undone, ...$putBack]]);
        }

        $this->line('Put back '.count($putBack).' of '.count($steps).'.');

        foreach ($failures as $failure) {
            $this->line('  Left alone: '.OutputFormatter::escape($failure));
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $commands
     * @param  ?array{repo: string, message: string}  $commit
     * @param  ?string  $hint  a line to print under the commands
     */
    private function showLeftAlone(array $commands, ?array $commit, ?string $hint = null): void
    {
        if ($commands !== []) {
            $this->line('These ran and stay as they are:');

            foreach ($commands as $command) {
                $this->line('  '.OutputFormatter::escape($command));
            }

            if ($hint !== null) {
                $this->line($hint);
            }
        }

        if ($commit !== null) {
            $this->line("The sync committed \"{$commit['message']}\" in {$commit['repo']}. Take that back with git revert.");
        }
    }

    /**
     * A step that fails doesn't stop the ones after it.
     *
     * @param  array<string, Step>  $steps
     * @return array<string, string> one line per step that failed, with why, by the step's key
     */
    private function putBack(array $steps): array
    {
        $failures = [];

        foreach ($steps as $key => $step) {
            try {
                ($step->run)();
            } catch (RuntimeException|ErrorException $e) {
                $failures[$key] = "{$step->description}: {$e->getMessage()}";
            }
        }

        return $failures;
    }
}
