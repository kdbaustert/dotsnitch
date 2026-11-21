<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ProcessResult
{
    /**
     * @param  string  $output  stdout
     * @param  string  $errorOutput  stderr
     */
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput = '',
    ) {}

    /**
     * True only for exit code 0.
     */
    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
