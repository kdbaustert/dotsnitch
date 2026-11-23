<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ChoosesHomebrew;
use App\Contracts\ListsOutdated;
use App\Data\Package;
use App\Support\PathResolver;
use ErrorException;
use Fiber;
use InvalidArgumentException;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

final class OutdatedCommand extends Command
{
    use ChoosesHomebrew;

    /** How long the wait for one package manager sleeps between looks at the commands running. */
    private const POLL_MICROSECONDS = 2000;

    protected $signature = 'outdated
        {--checks= : Package managers to ask, comma-separated (such as brew,npm); defaults to every one that\'s installed}
        {--json : Print the list as JSON and nothing else}';

    protected $description = 'List the installed packages that have a newer version';

    /**
     * Asks each package manager that's installed and can say. It only lists: upgrading is left to
     * the package manager, and none of this counts as out of sync. They're asked side by side,
     * each in a fiber that steps aside while its command runs (see ProcessShell).
     *
     * @return int failure when a package manager couldn't be asked or printed a list that can't be
     *             read; the others are still listed
     *
     * @throws InvalidArgumentException when --checks names one that doesn't exist or isn't installed
     */
    public function handle(PathResolver $paths): int
    {
        // The spinner goes through the same output as the JSON.
        if ($this->option('json')) {
            $this->output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        }

        $this->resolveHomebrewPrefix($paths);
        $targets = $this->targets();
        $asks = [];

        foreach ($targets as $key => $target) {
            $asks[$key] = new Fiber(function () use ($target) {
                try {
                    return $target->outdated();
                } catch (RuntimeException|ErrorException $e) {
                    return $e;
                }
            });
            $asks[$key]->start();
        }

        $answers = [];

        foreach ($targets as $key => $target) {
            $answers[$key] = spin(function () use ($asks, $key) {
                while (! $asks[$key]->isTerminated()) {
                    foreach ($asks as $ask) {
                        if ($ask->isSuspended()) {
                            $ask->resume();
                        }
                    }

                    usleep(self::POLL_MICROSECONDS);
                }

                return $asks[$key]->getReturn();
            }, "Asking {$target->label()}…");
        }

        $failed = array_filter($answers, fn (array|RuntimeException|ErrorException $answer) => ! is_array($answer)) !== [];

        if ($this->option('json')) {
            $this->printJson($targets, $answers);
        } else {
            $this->printTable($targets, $answers, $failed);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, ListsOutdated> by key, the installed ones, narrowed to --checks when it's given
     *
     * @throws InvalidArgumentException for no keys, an unknown key, or one that isn't installed
     */
    private function targets(): array
    {
        $all = [];

        foreach ($this->laravel->tagged('sync-targets') as $target) {
            if ($target instanceof ListsOutdated) {
                $all[$target->key()] = $target;
            }
        }

        if ($this->option('checks') === null) {
            return array_filter($all, fn (ListsOutdated $target) => $target->isAvailable());
        }

        $keys = array_filter(array_map(trim(...), explode(',', $this->option('checks'))));
        $unknown = array_diff($keys, array_keys($all));

        if ($keys === []) {
            throw new InvalidArgumentException('--checks needs at least one check. Checks: '.implode(', ', array_keys($all)).'.');
        }

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown check "'.implode('", "', $unknown).'" in --checks. Checks: '.implode(', ', array_keys($all)).'.');
        }

        $selected = array_intersect_key($all, array_flip($keys));

        foreach ($selected as $target) {
            if (! $target->isAvailable()) {
                throw new InvalidArgumentException("{$target->label()} isn't installed on this Mac.");
            }
        }

        return $selected;
    }

    /**
     * @param  array<string, ListsOutdated>  $targets
     * @param  array<string, list<array{package: Package, latest: string}>|RuntimeException|ErrorException>  $answers
     */
    private function printTable(array $targets, array $answers, bool $failed): void
    {
        $rows = [];

        foreach ($answers as $key => $answer) {
            if (! is_array($answer)) {
                $this->error("{$targets[$key]->label()}: {$answer->getMessage()}");

                continue;
            }

            foreach ($answer as ['package' => $package, 'latest' => $latest]) {
                $rows[] = [$targets[$key]->label(), $package->name, $package->version ?? '', $latest];
            }
        }

        if ($rows !== []) {
            table(['Check', 'Package', 'Installed', 'Latest'], $rows);
        } elseif (! $failed) {
            $this->info('Everything is up to date.');
        }
    }

    /**
     * A package manager that couldn't be asked has its error and no packages.
     *
     * @param  array<string, ListsOutdated>  $targets
     * @param  array<string, list<array{package: Package, latest: string}>|RuntimeException|ErrorException>  $answers
     */
    private function printJson(array $targets, array $answers): void
    {
        $checks = [];

        foreach ($answers as $key => $answer) {
            $checks[$key] = [
                'label' => $targets[$key]->label(),
                'error' => is_array($answer) ? null : $answer->getMessage(),
                'packages' => array_map(fn (array $outdated) => [
                    'item' => $outdated['package']->key(),
                    'installed' => $outdated['package']->version,
                    'latest' => $outdated['latest'],
                ], is_array($answer) ? $answer : []),
            ];
        }

        $this->output->writeln(
            json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET,
        );
    }
}
