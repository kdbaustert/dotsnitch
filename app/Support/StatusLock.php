<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * One status scan at a time, so a prompt that finds the report stale doesn't start a second
 * refresh while the first is running. The lock goes when the process exits.
 */
final class StatusLock
{
    /** Kept next to the saved report. */
    public const FILE = 'status.lock';

    private const RETRY_MICROSECONDS = 200_000;

    /** @var ?resource kept open while this process holds the lock */
    private $handle = null;

    /**
     * @param  string  $path  created along with its directory
     */
    public function __construct(private readonly string $path) {}

    /**
     * @param  int  $waitSeconds  how long to keep trying while another process holds it; 0 tries once
     * @return bool false when another process still holds it, true when this one already does
     *
     * @throws RuntimeException when the lock file can't be created
     */
    public function acquire(int $waitSeconds = 0): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        $handle = $this->open();
        $giveUpAt = microtime(true) + $waitSeconds;

        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $giveUpAt) {
                fclose($handle);

                return false;
            }

            usleep(self::RETRY_MICROSECONDS);
        }

        $this->handle = $handle;

        return true;
    }

    /**
     * True while another process holds it. Asking takes and drops the lock, so it costs one open.
     *
     * @throws RuntimeException when the lock file can't be created
     */
    public function isHeld(): bool
    {
        $handle = $this->open();
        $free = flock($handle, LOCK_EX | LOCK_NB);
        fclose($handle);

        return ! $free;
    }

    /**
     * @return resource
     */
    private function open()
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}");
        }

        return @fopen($this->path, 'c') ?: throw new RuntimeException("Could not open {$this->path}");
    }
}
