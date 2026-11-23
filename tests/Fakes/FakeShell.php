<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\Shell;
use App\Data\ProcessResult;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class FakeShell implements Shell
{
    /** @var array<string, ProcessResult> */
    private array $results = [];

    /** @var array<string, list<ProcessResult>> results still to come for a command set up with expectInTurn() */
    private array $turns = [];

    /** @var array<string, true> commands that time out instead of returning */
    private array $timeouts = [];

    /** @var list<list<string>> */
    private array $ran = [];

    /** @var array<string, array<string, string>> the last environment passed, by command */
    private array $environments = [];

    /**
     * Sets what run() returns for this exact command.
     *
     * @param  list<string>  $command
     */
    public function expect(array $command, string $output = '', int $exitCode = 0, string $errorOutput = ''): self
    {
        $this->results[$this->keyFor($command)] = new ProcessResult($exitCode, $output, $errorOutput);

        return $this;
    }

    /**
     * Sets what run() returns each time this command runs, in order. The last output stays once
     * the others are used up.
     *
     * @param  list<string>  $command
     */
    public function expectInTurn(array $command, string $output, string ...$later): self
    {
        $this->expect($command, $output);
        $this->turns[$this->keyFor($command)] = array_map(fn (string $next) => new ProcessResult(0, $next, ''), $later);

        return $this;
    }

    /**
     * Makes run() throw the way ProcessShell does when a command hangs.
     *
     * @param  list<string>  $command
     */
    public function expectTimeout(array $command): self
    {
        $this->timeouts[$this->keyFor($command)] = true;

        return $this;
    }

    /**
     * Ignores the timeout. The environment is kept for environmentOf().
     *
     * @throws ProcessTimedOutException for a command set up with expectTimeout()
     * @throws RuntimeException for a command nobody expected, so a test can't pass by accident
     */
    public function run(array $command, ?int $timeout = 120, array $environment = []): ProcessResult
    {
        $key = $this->keyFor($command);

        if (isset($this->timeouts[$key])) {
            throw new ProcessTimedOutException(new Process($command, timeout: $timeout), ProcessTimedOutException::TYPE_GENERAL);
        }

        if (! isset($this->results[$key])) {
            throw new RuntimeException("FakeShell has no result for: {$key}");
        }

        $this->ran[] = $command;
        $this->environments[$key] = $environment;
        $result = $this->results[$key];

        if (($this->turns[$key] ?? []) !== []) {
            $this->results[$key] = array_shift($this->turns[$key]);
        }

        return $result;
    }

    /**
     * @return list<list<string>> commands in the order they ran
     */
    public function ran(): array
    {
        return $this->ran;
    }

    /**
     * @param  list<string>  $command
     * @return ?array<string, string> null when the command never ran
     */
    public function environmentOf(array $command): ?array
    {
        return $this->environments[$this->keyFor($command)] ?? null;
    }

    /**
     * @param  list<string>  $command
     */
    private function keyFor(array $command): string
    {
        return implode(' ', $command);
    }
}
