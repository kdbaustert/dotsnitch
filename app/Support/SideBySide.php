<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Fiber;

/**
 * Runs jobs at once, each in a fiber that steps aside while one of its commands runs (see
 * ProcessShell). Called inside a check's scan, it steps aside too while it waits, so the other
 * checks keep going.
 */
final class SideBySide
{
    /** How long it waits between looks at the commands, when no fiber around it does the waiting. */
    private const POLL_MICROSECONDS = 2000;

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  array<TKey, Closure(): TValue>  $jobs  started in this order
     * @return array<TKey, TValue> what each job returned, keyed and ordered like $jobs
     */
    public static function run(array $jobs): array
    {
        $fibers = array_map(fn (Closure $job) => new Fiber($job), $jobs);

        foreach ($fibers as $fiber) {
            $fiber->start();
        }

        while (array_filter($fibers, fn (Fiber $fiber) => ! $fiber->isTerminated()) !== []) {
            Fiber::getCurrent() === null ? usleep(self::POLL_MICROSECONDS) : Fiber::suspend();

            foreach ($fibers as $fiber) {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            }
        }

        return array_map(fn (Fiber $fiber) => $fiber->getReturn(), $fibers);
    }
}
