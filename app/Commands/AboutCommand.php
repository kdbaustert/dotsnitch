<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

/**
 * For checking a build, so it's hidden from the command list.
 */
final class AboutCommand extends Command
{
    protected $signature = 'about
        {--extensions : List the PHP extensions this dotsnitch has, one per line}';

    protected $description = 'Show the dotsnitch and PHP versions';

    protected $hidden = true;

    /**
     * The versions, or with --extensions the loaded extensions in lowercase, sorted, so the build
     * can compare them with scripts/extensions.txt.
     */
    public function handle(): int
    {
        if (! $this->option('extensions')) {
            $this->line(config('app.name').' '.config('app.version').', PHP '.PHP_VERSION);

            return self::SUCCESS;
        }

        $extensions = array_map(strtolower(...), get_loaded_extensions());
        sort($extensions);

        foreach ($extensions as $extension) {
            $this->line($extension);
        }

        return self::SUCCESS;
    }
}
