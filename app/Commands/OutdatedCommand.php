<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ChoosesHomebrew;
use App\Contracts\ListsOutdated;
use App\Support\PathResolver;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

final class OutdatedCommand extends Command
{
    use ChoosesHomebrew;

    protected $signature = 'outdated';

    protected $description = 'List the installed Homebrew, npm and Composer packages that have a newer version';

    /**
     * Asks each package manager that's installed and can say. It only lists: upgrading is left to
     * the package manager, and none of this counts as out of sync.
     *
     * @return int failure when a package manager couldn't be asked; the others are still listed
     */
    public function handle(PathResolver $paths): int
    {
        $this->resolveHomebrewPrefix($paths);
        $rows = [];
        $failed = false;

        foreach ($this->laravel->tagged('sync-targets') as $target) {
            if (! $target instanceof ListsOutdated || ! $target->isAvailable()) {
                continue;
            }

            try {
                $outdated = spin($target->outdated(...), "Asking {$target->label()}…");
            } catch (RuntimeException $e) {
                $this->error("{$target->label()}: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            foreach ($outdated as ['package' => $package, 'latest' => $latest]) {
                $rows[] = [$target->label(), $package->name, $package->version ?? '', $latest];
            }
        }

        if ($rows !== []) {
            table(['Check', 'Package', 'Installed', 'Latest'], $rows);
        } elseif (! $failed) {
            $this->info('Everything is up to date.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
