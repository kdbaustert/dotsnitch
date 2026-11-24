<?php

declare(strict_types=1);

use App\Support\ProcessShell;
use App\Support\SideBySide;

function sleepThenPrint(string $word): Closure
{
    return fn () => (new ProcessShell)->run(['sh', '-c', "sleep 0.4; printf {$word}"])->output;
}

it('runs the jobs at once and keeps their keys and order', function () {
    $started = microtime(true);
    $outputs = SideBySide::run(['b' => sleepThenPrint('two'), 'a' => sleepThenPrint('one')]);

    expect($outputs)->toBe(['b' => 'two', 'a' => 'one'])
        ->and(microtime(true) - $started)->toBeLessThan(0.7);
});

it('steps aside inside a fiber while its jobs wait', function () {
    $outer = new Fiber(fn () => SideBySide::run([sleepThenPrint('one'), sleepThenPrint('two')]));
    $outer->start();
    $suspended = $outer->isSuspended();

    while (! $outer->isTerminated()) {
        usleep(2000);
        $outer->resume();
    }

    expect($suspended)->toBeTrue()
        ->and($outer->getReturn())->toBe(['one', 'two']);
});

it('throws what a job throws', function () {
    SideBySide::run([fn () => throw new RuntimeException('boom')]);
})->throws(RuntimeException::class, 'boom');

it('stops the commands the other jobs were waiting on when a job throws', function () {
    $sleep = fn () => (new ProcessShell)->run(['sh', '-c', 'sleep 7.29'])->output;

    expect(fn () => SideBySide::run([$sleep, fn () => throw new RuntimeException('boom')]))
        ->toThrow(RuntimeException::class, 'boom');

    exec("pgrep -f 'sleep 7.29'", $running);
    expect($running)->toBe([]);
});
