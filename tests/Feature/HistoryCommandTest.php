<?php

declare(strict_types=1);

use App\Sync\ReportStore;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-history-'.bin2hex(random_bytes(4));
    mkdir("{$this->dir}/reports", 0755, true);
    $this->app->instance(ReportStore::class, new ReportStore($this->dir));
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('lists the saved scans newest first, and skips one it can\'t read', function () {
    file_put_contents("{$this->dir}/reports/2026-11-20T090000.json", json_encode([
        'scanned_at' => '2026-11-20T09:00:00-05:00',
        'repo' => '/Users/me/dotfiles',
        'checks' => ['brew' => ['brew:untracked:formula:jq'], 'npm' => []],
    ]));
    file_put_contents("{$this->dir}/reports/2026-11-21T090000.json", json_encode([
        'scanned_at' => '2026-11-21T09:00:00-05:00',
        'repo' => '/Users/me/dotfiles',
        'checks' => ['brew' => ['brew:untracked:formula:jq', 'brew:missing:formula:gh'], 'npm' => ['npm:missing:package:tsx']],
        'new' => ['brew' => ['brew:missing:formula:gh'], 'npm' => ['npm:missing:package:tsx']],
    ]));
    file_put_contents("{$this->dir}/reports/2026-11-22T090000.json", '{"scanned_at": 5}');

    $scans = app(ReportStore::class)->history();

    expect(array_map(fn ($scan) => $scan->count(), $scans))->toBe([3, 1]);

    expect(Artisan::call('history'))->toBe(0);
    $rows = array_values(array_filter(explode("\n", Artisan::output()), fn (string $line) => str_contains($line, 'dotfiles')));

    expect($rows)->toHaveCount(2)
        ->and(preg_split('/\s*│\s*/u', trim($rows[0], ' │')))->toBe(['Nov 21, 09:00', '3', '2', 'brew 2, npm 1', '/Users/me/dotfiles'])
        ->and(preg_split('/\s*│\s*/u', trim($rows[1], ' │')))->toBe(['Nov 20, 09:00', '1', '0', 'brew 1', '/Users/me/dotfiles']);
});

it('says so before the first scan', function () {
    $this->artisan('history')
        ->expectsOutput('No scan has been saved yet. Run dotsnitch status.')
        ->assertExitCode(0);
});
