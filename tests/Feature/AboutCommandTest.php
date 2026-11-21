<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('lists the loaded extensions in lowercase, sorted', function () {
    Artisan::call('about', ['--extensions' => true]);
    $listed = explode("\n", trim(Artisan::output()));
    $sorted = $listed;
    sort($sorted);

    expect($listed)->toBe($sorted)
        ->and($listed)->toContain('core')
        ->and($listed)->toHaveCount(count(get_loaded_extensions()));
});

it('stays out of the command list', function () {
    Artisan::call('list', ['--raw' => true]);

    expect(Artisan::output())->not->toContain('about');
});
