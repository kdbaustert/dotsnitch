<?php

declare(strict_types=1);

use App\Support\Machine;
use Tests\Fakes\FakeShell;

it('detects Apple Silicon running natively', function () {
    $machine = new Machine((new FakeShell)
        ->expect(['/usr/sbin/sysctl', '-n', 'hw.optional.arm64'], "1\n")
        ->expect(['/usr/sbin/sysctl', '-n', 'sysctl.proc_translated'], "0\n"));

    expect($machine->isAppleSilicon())->toBeTrue()
        ->and($machine->isTranslated())->toBeFalse()
        ->and($machine->runsUnderRosetta())->toBeFalse();
});

it('detects a PHP running under Rosetta', function () {
    $machine = new Machine((new FakeShell)
        ->expect(['/usr/sbin/sysctl', '-n', 'hw.optional.arm64'], "1\n")
        ->expect(['/usr/sbin/sysctl', '-n', 'sysctl.proc_translated'], "1\n"));

    expect($machine->runsUnderRosetta())->toBeTrue();
});

it('treats the missing arm64 key on Intel as not Apple Silicon', function () {
    $machine = new Machine((new FakeShell)
        ->expect(['/usr/sbin/sysctl', '-n', 'hw.optional.arm64'], exitCode: 1, errorOutput: "sysctl: unknown oid 'hw.optional.arm64'"));

    expect($machine->isAppleSilicon())->toBeFalse();
});

it('reads the hostname from scutil', function () {
    $machine = new Machine((new FakeShell)
        ->expect(['/usr/sbin/scutil', '--get', 'LocalHostName'], "Johns-MacBook-Pro\n"));

    expect($machine->hostname())->toBe('Johns-MacBook-Pro');
});
