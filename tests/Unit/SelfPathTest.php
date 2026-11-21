<?php

declare(strict_types=1);

use App\Support\SelfPath;

it('runs itself from source as PHP plus the entry script', function () {
    expect(SelfPath::command())->toBe([realpath(PHP_BINARY), realpath(dirname(__DIR__, 2).'/dotsnitch')]);
});

it('runs the standalone build as just the binary, with symlinks resolved', function () {
    $dir = realpath(sys_get_temp_dir()).'/dotsnitch-self-'.bin2hex(random_bytes(4));
    mkdir($dir);
    touch("{$dir}/dotsnitch");
    symlink("{$dir}/dotsnitch", "{$dir}/linked");

    try {
        // micro leaves PHP_BINARY empty; other runtimes name the binary itself.
        expect(SelfPath::forRuntime('', "{$dir}/linked"))->toBe(["{$dir}/dotsnitch"])
            ->and(SelfPath::forRuntime("{$dir}/dotsnitch", "{$dir}/linked"))->toBe(["{$dir}/dotsnitch"]);
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

it('runs a PHAR with the PHP that started it', function () {
    expect(SelfPath::forRuntime('/opt/php/bin/php', '/opt/dotsnitch/dotsnitch.phar'))
        ->toBe(['/opt/php/bin/php', '/opt/dotsnitch/dotsnitch.phar']);
});
