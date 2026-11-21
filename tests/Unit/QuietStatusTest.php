<?php

declare(strict_types=1);

use App\Support\QuietStatus;
use App\Support\SelfPath;
use App\Support\StatusLock;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-quiet-'.bin2hex(random_bytes(4));
    $this->state = "{$this->dir}/dotsnitch";
    mkdir($this->state, 0755, true);
    $this->environment = ['XDG_STATE_HOME' => $this->dir];
    $this->spawned = [];
    $this->spawn = function (array $command) {
        $this->spawned[] = $command;
    };
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * Saves a report $age seconds old with two items out of sync, one of them new.
 */
function savedReport(string $state, int $age): void
{
    file_put_contents("{$state}/last-report.json", json_encode([
        'scanned_at' => date(DATE_ATOM, time() - $age),
        'repo' => '/repo',
        'checks' => ['brew' => ['brew:untracked:formula:jq', 'brew:missing:formula:gh']],
        'new' => ['brew' => ['brew:missing:formula:gh']],
    ]));
}

/**
 * What run() printed.
 */
function quietOutput(QuietStatus $status): string
{
    ob_start();
    $status->run();

    return (string) ob_get_clean();
}

it('answers only status with --quiet or -q', function (array $argv, bool $handled) {
    expect(QuietStatus::handles(['dotsnitch', ...$argv]))->toBe($handled);
})->with([
    [['status', '--quiet'], true],
    [['--no-ansi', 'status', '-q'], true],
    [['status'], false],
    [['sync', '--quiet'], false],
    [['--quiet'], false],
    [['--repo', '/Users/me/dotfiles', 'status', '-q'], true],
    [['--repo', 'status', 'sync', '--quiet'], false],
]);

it('prints the saved count, or the new count with --new', function () {
    savedReport($this->state, 60);

    expect(quietOutput(new QuietStatus(['dotsnitch', 'status', '--quiet'], $this->environment, $this->spawn)))->toBe("2\n")
        ->and(quietOutput(new QuietStatus(['dotsnitch', 'status', '-q', '--new'], $this->environment, $this->spawn)))->toBe("1\n")
        ->and($this->spawned)->toBe([]);
});

it('prints nothing for a fresh report with nothing out of sync', function () {
    file_put_contents("{$this->state}/last-report.json", json_encode([
        'scanned_at' => date(DATE_ATOM, time() - 60),
        'repo' => '/repo',
        'checks' => ['brew' => []],
        'new' => ['brew' => []],
    ]));

    expect(quietOutput(new QuietStatus(['dotsnitch', 'status', '-q'], $this->environment, $this->spawn)))->toBe('')
        ->and($this->spawned)->toBe([]);
});

it('prints nothing for an unreadable report, and refreshes to replace it', function () {
    file_put_contents("{$this->state}/last-report.json", '{not json');

    expect(quietOutput(new QuietStatus(['dotsnitch', 'status', '-q'], $this->environment, $this->spawn)))->toBe('')
        ->and($this->spawned)->toHaveCount(1);
});

it('prints nothing and starts nothing without a HOME', function () {
    expect(quietOutput(new QuietStatus(['dotsnitch', 'status', '-q'], [], $this->spawn)))->toBe('')
        ->and($this->spawned)->toBe([]);
});

it('starts one refresh when the report is older than --max-age, keeping --repo', function () {
    savedReport($this->state, 7200);
    $argv = ['dotsnitch', 'status', '--quiet', '--repo=/repo', '--max-age', '1h'];

    expect(quietOutput(new QuietStatus($argv, $this->environment, $this->spawn)))->toBe("2\n");
    quietOutput(new QuietStatus($argv, $this->environment, $this->spawn));

    expect($this->spawned)->toBe([[...SelfPath::command(), 'status', '--repo=/repo', '--no-interaction']])
        ->and(file_exists("{$this->state}/".StatusLock::FILE))->toBeTrue();
});

it('refreshes when there is no report yet', function () {
    quietOutput(new QuietStatus(['dotsnitch', 'status', '-q'], $this->environment, $this->spawn));

    expect($this->spawned)->toHaveCount(1);
});

it('waits out --max-age after a refresh that left the report stale', function () {
    savedReport($this->state, 7200);
    touch("{$this->state}/".StatusLock::FILE, time() - 1800);

    quietOutput(new QuietStatus(['dotsnitch', 'status', '-q', '--max-age=1h'], $this->environment, $this->spawn));

    expect($this->spawned)->toBe([]);
});

it('leaves a running refresh alone', function () {
    $lock = new StatusLock("{$this->state}/".StatusLock::FILE);
    $lock->acquire();
    touch("{$this->state}/".StatusLock::FILE, time() - 90000);

    quietOutput(new QuietStatus(['dotsnitch', 'status', '-q'], $this->environment, $this->spawn));

    expect($this->spawned)->toBe([]);
});

it('falls back to 24 hours for a --max-age it cannot read', function () {
    savedReport($this->state, 7200);

    quietOutput(new QuietStatus(['dotsnitch', 'status', '-q', '--max-age=soon'], $this->environment, $this->spawn));

    expect($this->spawned)->toBe([]);
});
