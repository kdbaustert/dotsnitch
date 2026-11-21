<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ProcessResult;

interface Shell
{
    /**
     * Runs a command without a shell, so arguments are never re-parsed.
     *
     * @param  list<string>  $command
     * @param  ?int  $timeout  seconds; null waits forever
     * @param  array<string, string>  $environment  extra variables for this command only
     */
    public function run(array $command, ?int $timeout = 120, array $environment = []): ProcessResult;
}
