<?php

declare(strict_types=1);

use App\Support\ProcessShell;
use Tests\Fakes\FakeShell;

it('captures output and exit code from a real process', function () {
    $result = (new ProcessShell)->run(['sh', '-c', 'printf out; printf err >&2; exit 3']);

    expect($result->exitCode)->toBe(3)
        ->and($result->output)->toBe('out')
        ->and($result->errorOutput)->toBe('err')
        ->and($result->successful())->toBeFalse();
});

it('passes arguments through without shell parsing', function () {
    $result = (new ProcessShell)->run(['printf', '%s', 'a b; echo c']);

    expect($result->output)->toBe('a b; echo c');
});

it('passes the given environment to the process', function () {
    $result = (new ProcessShell(fn () => ['DOTSNITCH_TEST' => 'yes']))->run(['sh', '-c', 'printf %s "$DOTSNITCH_TEST"']);

    expect($result->output)->toBe('yes');
});

it('steps aside inside a fiber, so two commands wait at the same time', function () {
    $shell = new ProcessShell;
    $fibers = array_map(
        fn (string $word) => new Fiber(fn () => $shell->run(['sh', '-c', "sleep 0.4; printf {$word}"])->output),
        ['one', 'two'],
    );
    $started = microtime(true);
    array_map(fn (Fiber $fiber) => $fiber->start(), $fibers);

    while (array_filter($fibers, fn (Fiber $fiber) => ! $fiber->isTerminated()) !== []) {
        array_map(fn (Fiber $fiber) => $fiber->isSuspended() && $fiber->resume(), $fibers);
        usleep(2000);
    }

    expect(array_map(fn (Fiber $fiber) => $fiber->getReturn(), $fibers))->toBe(['one', 'two'])
        ->and(microtime(true) - $started)->toBeLessThan(0.7);
});

it('replays canned results and records what ran', function () {
    $shell = (new FakeShell)
        ->expect(['brew', '--prefix'], "/opt/homebrew\n")
        ->expect(['brew', 'tap'], exitCode: 1, errorOutput: 'boom');

    expect($shell->run(['brew', '--prefix'])->output)->toBe("/opt/homebrew\n")
        ->and($shell->run(['brew', 'tap'])->errorOutput)->toBe('boom')
        ->and($shell->ran())->toBe([['brew', '--prefix'], ['brew', 'tap']]);
});

it('fails loudly on a command it was not given', function () {
    (new FakeShell)->run(['brew', 'list']);
})->throws(RuntimeException::class, 'FakeShell has no result for: brew list');

it("doesn't pass Symfony's SHELL_VERBOSITY on to child tools", function () {
    putenv('SHELL_VERBOSITY=-1');
    $_ENV['SHELL_VERBOSITY'] = $_SERVER['SHELL_VERBOSITY'] = '-1';

    try {
        $result = (new ProcessShell)->run(['sh', '-c', 'printf %s "${SHELL_VERBOSITY-unset}"']);
    } finally {
        putenv('SHELL_VERBOSITY');
        unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);
    }

    expect($result->output)->toBe('unset');
});
