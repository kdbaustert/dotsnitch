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
     * Exit code 0 and nothing else. A caller that expects another code, such as diff's 1 for
     * differences, reads exitCode itself.
     */
    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
