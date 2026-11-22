<?php

declare(strict_types=1);

use App\Support\LaunchAgent;
use App\Support\Notifier;
use App\Support\PathResolver;
use App\Support\StatusLock;
use App\Sync\ReportStore;
use Tests\Fakes\FakeShell;

beforeEach(function () {
    fakeMac($this);
    onlyOtherCheck();
    $this->settings->set('checks', ['other']);
    $this->notifications = new FakeShell;
    $this->app->instance(Notifier::class, new Notifier($this->notifications, app(PathResolver::class)));
    $this->app->instance(LaunchAgent::class, new LaunchAgent(new FakeShell, $this->dir, "{$this->dir}/state/schedule.log"));
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * Saves a scan from Nov 20 that found $other in the Other check.
 *
 * @param  list<string>  $other  drift keys
 */
function previousScan(string $dir, array $other): void
{
    mkdir("{$dir}/state", 0755, true);
    file_put_contents("{$dir}/state/last-report.json", json_encode([
        'scanned_at' => '2026-11-20T09:00:00-05:00',
        'repo' => realpath("{$dir}/repo"),
        'checks' => ['other' => $other],
    ]));
}

it('runs the last picked checks without asking and exits 0 with drift', function () {
    $this->artisan('status', ['--repo' => "{$this->dir}/repo"])
        ->doesntExpectOutputToContain('boom')
        ->expectsOutputToContain('1 out of sync')
        ->expectsOutputToContain('untracked  formula:jq')
        ->expectsOutputToContain('Go through them with dotsnitch sync.')
        ->assertExitCode(0);

    expect(app(ReportStore::class)->latest()->checks)->toBe(['other' => ['other:untracked:formula:jq']]);
});

it('leaves what each side has for a package to sync', function () {
    $this->artisan('status', ['--repo' => "{$this->dir}/repo"])
        ->expectsOutputToContain('untracked  formula:jq')
        ->doesntExpectOutputToContain('not listed')
        ->assertExitCode(0);
});

it("doesn't point at sync when everything matches", function () {
    onlyOtherCheck()->kinds = [];

    $this->artisan('status', ['--repo' => "{$this->dir}/repo"])
        ->doesntExpectOutputToContain('dotsnitch sync')
        ->assertExitCode(0);
});

it('skips anything in the saved checks that isn\'t a check key', function () {
    $this->settings->set('checks', [['other'], null, 'other']);

    $this->artisan('status', ['--repo' => "{$this->dir}/repo"])
        ->expectsOutputToContain('1 out of sync')
        ->assertExitCode(0);
});

it('marks what the last scan of that check didn\'t have', function () {
    previousScan($this->dir, []);

    $this->artisan('status', ['--repo' => "{$this->dir}/repo"])
        ->expectsOutputToContain('1 out of sync, 1 new since Nov 20, 09:00')
        ->expectsOutputToContain('untracked (new)')
        ->assertExitCode(0);
});

it('leaves the new count out when nothing is new', function () {
    previousScan($this->dir, ['other:untracked:formula:jq']);

    $this->artisan('status', ['--repo' => "{$this->dir}/repo"])
        ->expectsOutputToContain('1 out of sync')
        ->expectsOutputToContain('untracked  formula:jq')
        ->doesntExpectOutputToContain('new since')
        ->assertExitCode(0);
});

it('lists only new items with --new', function () {
    previousScan($this->dir, ['other:untracked:formula:jq']);

    $this->artisan('status', ['--repo' => "{$this->dir}/repo", '--new' => true])
        ->expectsOutputToContain('1 out of sync, 0 new since Nov 20, 09:00')
        ->doesntExpectOutputToContain('formula:jq')
        ->assertExitCode(0);
});

it('posts what is new with --notify', function () {
    previousScan($this->dir, []);
    $this->notifications->expect(['/usr/bin/osascript', '-e', 'on run argv', '-e', 'display notification (item 1 of argv) with title "dotsnitch"', '-e', 'end run', '1 new item out of sync (jq)']);

    $this->artisan('status', ['--repo' => "{$this->dir}/repo", '--notify' => true])->assertExitCode(0);

    expect($this->notifications->ran())->toHaveCount(1);
});

it('stays quiet with --notify when nothing is new, and remembers what it saw', function () {
    previousScan($this->dir, ['other:untracked:formula:jq']);

    $this->artisan('status', ['--repo' => "{$this->dir}/repo", '--notify' => true])->assertExitCode(0);

    expect($this->notifications->ran())->toBe([])
        ->and(app(ReportStore::class)->notified())->toBe(['other' => ['other:untracked:formula:jq']]);
});

it('compares --notify with what the last --notify saw, not the scan before', function (array $notified, int $posts) {
    // The scan before already had jq, as when a sync or a prompt's refresh ran in between.
    previousScan($this->dir, ['other:untracked:formula:jq']);
    app(ReportStore::class)->saveNotified($notified);
    $this->notifications->expect(['/usr/bin/osascript', '-e', 'on run argv', '-e', 'display notification (item 1 of argv) with title "dotsnitch"', '-e', 'end run', '1 new item out of sync (jq)']);

    $this->artisan('status', ['--repo' => "{$this->dir}/repo", '--notify' => true])->assertExitCode(0);

    expect($this->notifications->ran())->toHaveCount($posts)
        ->and(app(ReportStore::class)->notified())->toBe([...$notified, 'other' => ['other:untracked:formula:jq']]);
})->with([
    'not posted yet' => [['other' => [], 'brew' => ['brew:missing:formula:gh']], 1],
    'posted before' => [['other' => ['other:untracked:formula:jq']], 0],
]);

it('skips the scan while another status holds the lock', function () {
    $other = new StatusLock("{$this->dir}/state/".StatusLock::FILE);
    expect($other->acquire())->toBeTrue();

    $this->artisan('status', ['--repo' => "{$this->dir}/repo"])
        ->expectsOutputToContain('Another dotsnitch status or sync is already running.')
        ->assertExitCode(0);

    expect(app(ReportStore::class)->latest())->toBeNull();
});
