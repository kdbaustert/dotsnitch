<?php

declare(strict_types=1);

use App\Support\LocalTimezone;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-zone-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('reads the zone from where the localtime link points', function (string $target, string $zone) {
    symlink($target, "{$this->dir}/localtime");

    expect(LocalTimezone::fromLink("{$this->dir}/localtime"))->toBe($zone);
})->with([
    ['/var/db/timezone/zoneinfo/America/New_York', 'America/New_York'],
    ['/usr/share/zoneinfo/Europe/London', 'Europe/London'],
    ['/var/db/timezone/zoneinfo/Not/A_Zone', 'UTC'],
    ['/etc/somewhere-else', 'UTC'],
]);

it('falls back to UTC when there is no link', function () {
    expect(LocalTimezone::fromLink("{$this->dir}/missing"))->toBe('UTC');
});
