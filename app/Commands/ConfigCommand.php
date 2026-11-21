<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ChoosesHomebrew;
use App\Contracts\SyncTarget;
use App\Support\DotfilesRepo;
use App\Support\Machine;
use App\Support\Manifests;
use App\Support\PathResolver;
use App\Sync\IgnoreList;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class ConfigCommand extends Command
{
    use ChoosesHomebrew;

    /** Binaries the checks will need, in the order the plan lists the checks. */
    private const BINARIES = ['brew', 'mas', 'composer', 'npm', 'pnpm', 'gh', 'pipx', 'uv', 'cargo', 'node', 'php'];

    protected $signature = 'config
        {--machine : Print what dotsnitch detected about this Mac}
        {--repo= : Path to your dotfiles directory}
        {--ignored : List what is ignored on this Mac and pick any to stop ignoring}';

    protected $description = 'Show dotsnitch settings for this Mac';

    /**
     * Lists the manifest each check will read. With --machine, shows what was detected about this
     * Mac instead, and with --ignored, what's ignored here.
     */
    public function handle(Machine $machine, PathResolver $paths, DotfilesRepo $repo, Manifests $manifests, IgnoreList $ignores): int
    {
        if ($this->option('ignored')) {
            $this->showIgnored($ignores);

            return self::SUCCESS;
        }

        if (! $this->option('machine')) {
            $root = $repo->resolve($this->option('repo'));
            $this->line("Dotfiles: {$root}");
            $manifests = $this->input->isInteractive() ? $manifests : $manifests->withDefaults();
            $this->showManifests($manifests->chosen($root, $machine->hostname()), $manifests);

            return self::SUCCESS;
        }

        $prefix = $this->resolveHomebrewPrefix($paths);

        if ($machine->runsUnderRosetta()) {
            warning('PHP is running under Rosetta, so packages may install into the Intel Homebrew.');
        }

        table(['', ''], [
            ['Architecture', $machine->isAppleSilicon() ? 'Apple Silicon' : 'Intel'],
            ['Rosetta', $machine->isTranslated() ? 'yes' : 'no'],
            ['Hostname', $machine->hostname()],
            ['Homebrew prefix', $prefix ?? 'not installed'],
            ['Node', $paths->nodeDescription() ?? 'not installed'],
        ]);

        table(['Binary', 'Path'], array_map(
            fn (string $binary) => [$binary, $paths->find($binary) ?? 'not found'],
            self::BINARIES,
        ));

        table(['PATH'], array_map(fn (string $dir) => [$dir], explode(':', $paths->homeEnvironment()['PATH'])));

        foreach ($paths->warnings() as $message) {
            warning($message);
        }

        return self::SUCCESS;
    }

    /**
     * One row per check. A check that reads several files lists them on separate lines.
     *
     * @param  array<string, list<string>>  $chosen  from Manifests::chosen()
     */
    private function showManifests(array $chosen, Manifests $manifests): void
    {
        $rows = [];

        foreach ($chosen as $check => $files) {
            $rows[] = [$manifests->label($check), $files === [] ? 'none found' : implode("\n", $files)];
        }

        table(['Check', 'Manifest'], $rows);
    }

    /**
     * Lists every ignore, then offers to take some off the list. Without a terminal it only lists.
     */
    private function showIgnored(IgnoreList $ignores): void
    {
        $ignored = $ignores->all();

        if ($ignored === []) {
            $this->line('Nothing is ignored on this Mac.');

            return;
        }

        $labels = [];

        foreach ($this->laravel->tagged('sync-targets') as $target) {
            /** @var SyncTarget $target */
            $labels[$target->key()] = $target->label();
        }

        // Check keys have no spaces, so the first one splits a choice back into check and key.
        $choices = [];

        foreach ($ignored as $check => $keys) {
            foreach ($keys as $key) {
                $choices["{$check} {$key}"] = [$labels[$check] ?? $check, $key];
            }
        }

        table(['Check', 'Ignored'], array_values($choices));

        if (! $this->input->isInteractive()) {
            return;
        }

        $picked = multiselect(
            'Stop ignoring which of these?',
            array_map(fn (array $row) => "{$row[0]}: {$row[1]}", $choices),
            hint: 'Pick none to keep them all.',
        );

        $this->stopIgnoring($ignores, $picked);
    }

    /**
     * @param  list<string>  $picked  choices from showIgnored(), "<check> <key>"
     */
    private function stopIgnoring(IgnoreList $ignores, array $picked): void
    {
        $byCheck = [];

        foreach ($picked as $choice) {
            [$check, $key] = explode(' ', $choice, 2);
            $byCheck[$check][] = $key;
        }

        foreach ($byCheck as $check => $keys) {
            $ignores->remove($check, $keys);
        }

        if ($picked !== []) {
            $this->info('Stopped ignoring '.count($picked).'. The next sync reports them again.');
        }
    }
}
