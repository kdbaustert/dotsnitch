<?php

declare(strict_types=1);

namespace App\Sync;

use Closure;

/**
 * One command or file edit from a decision. --dry-run prints the description and never calls run.
 */
final readonly class Step
{
    /**
     * @param  string  $description  the exact command, or the edit and file name
     * @param  Closure(): array<string, mixed>  $run  returns what it changed, in one of SyncLog's shapes; throws RuntimeException when the step fails
     */
    public function __construct(
        public string $description,
        public Closure $run,
    ) {}

    /**
     * The command as you'd type it, quoting only what needs it.
     *
     * @param  list<string>  $command
     */
    public static function commandLine(array $command): string
    {
        return implode(' ', array_map(
            fn (string $argument) => preg_match('/^[\w.\/:@=+-]+$/', $argument) ? $argument : escapeshellarg($argument),
            $command,
        ));
    }
}
