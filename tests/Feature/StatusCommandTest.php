<?php

declare(strict_types=1);

use App\Support\LaunchAgent;
use App\Support\Notifier;
use App\Support\PathResolver;
use App\Support\StatusLock;
use App\Sync\ReportStore;
use App\Targets\Homebrew;
use App\Targets\Symlinks;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Tests\Fakes\FakeShell;

beforeEach(function () {
    fakeMac($this);
    $this->other = onlyOtherCheck();
    // Not installed, so status leaves them out and only the Other check runs.
    $this->app->instance(Homebrew::class, fakeTarget('brew', fails: false, available: false));
    $this->app->instance(Symlinks::class, fakeTarget('symlinks', fails: false, available: false));
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

it('runs every ready check without asking, whatever sync last picked, and exits 0 with drift', function () {
    $this->settings->set('checks', ['brew']);

    $this->artisan('status', ['--repo' => "{$this->dir}/repo"])
        ->expectsOutputToContain('1 out of sync')
        ->expectsOutputToContain('untracked  formula:jq')
        ->expectsOutputToContain('Go through them with dotsnitch sync.')
        ->assertExitCode(0);

    expect(app(ReportStore::class)->latest()->checks)->toBe(['other' => ['other:untracked:formula:jq']]);
});

// The terminal is 80 wide under test.
it('lists two checks side by side when both fit across the terminal', function () {
    $this->app->instance(Homebrew::class, fakeTarget('brew', fails: false));

    $this->artisan('status', ['--repo' => "{$this->dir}/repo"])
        ->expectsOutput('Brew                       Other')
        ->expectsOutput('  untracked  formula:jq      untracked  formula:jq')
        ->assertExitCode(0);
});

it('lists two checks one under the other when the pair is wider than the terminal', function () {
    $this->app->instance(Homebrew::class, fakeTarget('brew', fails: false));
    $this->other->package = str_repeat('a', 50);

    $this->artisan('status', ['--repo' => "{$this->dir}/repo"])
        ->expectsOutput('Brew')
        ->expectsOutput('  untracked  formula:jq')
        ->expectsOutput('Other')
        ->expectsOutput('  untracked  formula:'.str_repeat('a', 50))
        ->assertExitCode(0);
});

it('leaves what each side has for a package to sync', function () {
    $this->artisan('status', ['--repo' => "{$this->dir}/repo"])
        ->expectsOutputToContain('untracked  formula:jq')
        ->doesntExpectOutputToContain('not listed')
        ->assertExitCode(0);
});

it('prints a name that looks like a color tag as it is', function () {
    $this->other->package = 'a<info>b';

    $this->artisan('status', ['--repo' => "{$this->dir}/repo"])
        ->expectsOutputToContain('untracked  formula:a<info>b')
        ->assertExitCode(0);
});

it("doesn't point at sync when everything matches", function () {
    $this->other->kinds = [];

    $this->artisan('status', ['--repo' => "{$this->dir}/repo"])
        ->doesntExpectOutputToContain('dotsnitch sync')
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

it('says the lock is taken on stderr, so --json leaves stdout empty', function () {
    $other = new StatusLock("{$this->dir}/state/".StatusLock::FILE);
    expect($other->acquire())->toBeTrue();
    // A console output with its own stderr, the way a terminal run gets one.
    $stdout = new class(fopen('php://memory', 'w+')) extends StreamOutput implements ConsoleOutputInterface
    {
        private OutputInterface $stderr;

        public function __construct($stream)
        {
            parent::__construct($stream);
            $this->stderr = new BufferedOutput;
        }

        public function getErrorOutput(): OutputInterface
        {
            return $this->stderr;
        }

        public function setErrorOutput(OutputInterface $error): void
        {
            $this->stderr = $error;
        }

        public function section(): ConsoleSectionOutput
        {
            throw new LogicException('status writes no sections');
        }
    };

    $exit = Artisan::call('status', ['--repo' => "{$this->dir}/repo", '--json' => true], $stdout);
    rewind($stdout->getStream());

    expect($exit)->toBe(1)
        ->and(stream_get_contents($stdout->getStream()))->toBe('')
        ->and($stdout->getErrorOutput()->fetch())->toContain('Another dotsnitch status or sync is already running.');
});

it('prints the scan as JSON and nothing else with --json', function () {
    previousScan($this->dir, []);

    $exit = Artisan::call('status', ['--repo' => "{$this->dir}/repo", '--json' => true]);
    $scan = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($scan['repo'])->toBe(realpath("{$this->dir}/repo"))
        ->and($scan['checks'])->toBe(['other' => [
            'label' => 'Other',
            'error' => null,
            'ignored' => 0,
            'items' => [['kind' => 'untracked', 'item' => 'formula:jq', 'detail' => null, 'new' => true]],
        ]]);
});

it('puts a failed check\'s error in the JSON and exits with failure', function () {
    $this->app->instance(Homebrew::class, fakeTarget('brew', fails: true));

    $exit = Artisan::call('status', ['--repo' => "{$this->dir}/repo", '--checks' => 'brew', '--json' => true]);
    $scan = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($scan['checks']['brew'])->toBe(['label' => 'Brew', 'error' => 'brew info failed: boom', 'ignored' => 0, 'items' => []]);
});
