<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\Shell;
use App\Data\ProcessResult;
use Closure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ProcessShell implements Shell
{
    /**
     * Symfony Console sets SHELL_VERBOSITY for -q and -v, and a child Composer or Symfony tool
     * obeys it, so `dotsnitch -q` would make `composer global show` print nothing. False unsets it.
     */
    private const UNSET = ['SHELL_VERBOSITY' => false];

    /**
     * @param  ?Closure(): array<string, string>  $environment  merged over the inherited environment on every run.
     *                                                          A closure so a changed Homebrew choice takes effect.
     */
    public function __construct(private readonly ?Closure $environment = null) {}

    /**
     * @throws ProcessTimedOutException when the timeout passes
     */
    public function run(array $command, ?int $timeout = 120, array $environment = []): ProcessResult
    {
        $base = $this->environment === null ? [] : ($this->environment)();
        $process = new Process($command, env: [...$base, ...$environment, ...self::UNSET], timeout: $timeout);
        $process->run();

        return new ProcessResult($process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }
}
