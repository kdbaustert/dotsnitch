<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\SelfPath;
use App\Support\SelfUpdate;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\spin;

final class SelfUpdateCommand extends Command
{
    protected $signature = 'self-update';

    protected $description = 'Replace this dotsnitch with the latest release';

    /**
     * Only the standalone build installed by scripts/install.sh updates itself. Homebrew's copy
     * belongs to brew, and a source checkout or PHAR has no release build to swap in.
     */
    public function handle(SelfUpdate $update): int
    {
        $command = SelfPath::command();

        if (count($command) !== 1) {
            $this->error('This dotsnitch runs from source or a PHAR, so there is no binary to replace.');

            return self::FAILURE;
        }

        if (str_contains($command[0], '/Cellar/')) {
            $this->error('Homebrew installed this dotsnitch. Run brew upgrade dotsnitch.');

            return self::FAILURE;
        }

        $running = config('app.version');

        if (spin(fn () => $update->releaseIsOlderThan($running), 'Asking for the latest release…')) {
            $this->info("This is {$running}, which is newer than the latest release, so there's nothing to install.");

            return self::SUCCESS;
        }

        try {
            $updated = spin(fn () => $update->update($command[0]), 'Downloading the latest release…');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($updated ? "Updated {$command[0]}. dotsnitch --version shows the new version." : 'Already on the latest release.');

        return self::SUCCESS;
    }
}
