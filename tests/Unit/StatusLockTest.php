<?php

declare(strict_types=1);

use App\Support\StatusLock;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/dotsnitch-lock-'.bin2hex(random_bytes(4)).'/'.StatusLock::FILE;
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg(dirname($this->path)));
});

it('keeps trying for the given seconds, then gives up', function () {
    // flock is per open file, so a second StatusLock in this process counts as another process.
    $other = new StatusLock($this->path);
    $other->acquire();
    $startedAt = microtime(true);

    expect((new StatusLock($this->path))->acquire(1))->toBeFalse()
        ->and(microtime(true) - $startedAt)->toBeGreaterThanOrEqual(1.0)
        ->and((new StatusLock($this->path))->isHeld())->toBeTrue();
});

it('takes a free lock straight away', function () {
    $startedAt = microtime(true);

    expect((new StatusLock($this->path))->acquire(5))->toBeTrue()
        ->and(microtime(true) - $startedAt)->toBeLessThan(1.0);
});
