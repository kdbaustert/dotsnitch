<?php

declare(strict_types=1);

use App\Data\ProcessResult;
use App\Support\ProcessShell;

/*
 * Runs the built binary, not the source, the way a Mac without PHP would. Point DOTSNITCH_BINARY
 * at it: DOTSNITCH_BINARY=builds/dotsnitch-arm64 vendor/bin/pest --testsuite=Binary
 */

/** What a status --quiet prompt can take before the prompt feels slow. */
const QUIET_BUDGET_SECONDS = 0.1;

beforeEach(function () {
    $binary = getenv('DOTSNITCH_BINARY');

    if ($binary === false || ! is_executable($binary)) {
        throw new RuntimeException('Set DOTSNITCH_BINARY to the built dotsnitch, such as builds/dotsnitch-arm64.');
    }

    $this->home = realpath(sys_get_temp_dir()).'/dotsnitch-binary-'.bin2hex(random_bytes(4));
    $this->repo = "{$this->home}/dotfiles";
    mkdir($this->home);
    exec('cp -R '.escapeshellarg(dirname(__DIR__).'/Fixtures/dotfiles').' '.escapeshellarg($this->repo));
    exec('git -C '.escapeshellarg($this->repo).' init --quiet');
    // dotsnitch finds the repo from a link already pointing into it. ~/.zshrc isn't linked yet.
    symlink("{$this->repo}/gitconfig", "{$this->home}/.gitconfig");
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->home));
});

/**
 * Runs the binary with only HOME and the system PATH, which has no PHP on macOS.
 *
 * @param  list<string>  $arguments
 */
function runBinary(string $home, array $arguments): ProcessResult
{
    return (new ProcessShell)->run(['/usr/bin/env', '-i', "HOME={$home}", 'PATH=/usr/bin:/bin', getenv('DOTSNITCH_BINARY'), ...$arguments]);
}

/**
 * The file's mtime now, not the one PHP cached on the last stat.
 */
function modifiedAt(string $path): int
{
    clearstatcache();

    return (int) filemtime($path);
}

it('runs without PHP on PATH', function () {
    expect(is_executable('/usr/bin/php') || is_executable('/bin/php'))->toBeFalse()
        ->and(runBinary($this->home, ['--version'])->output)->toStartWith('Dotsnitch ');
});

it('has every extension in scripts/extensions.txt', function () {
    $wanted = array_values(array_filter(
        array_map(trim(...), file(dirname(__DIR__, 2).'/scripts/extensions.txt')),
        fn (string $line) => $line !== '' && ! str_starts_with($line, '#'),
    ));
    $loaded = explode("\n", trim(runBinary($this->home, ['about', '--extensions'])->output));

    expect(array_values(array_diff($wanted, $loaded)))->toBe([]);
});

it('reads this Mac with sysctl and scutil', function () {
    $result = runBinary($this->home, ['config', '--machine']);

    expect($result->exitCode)->toBe(0)
        ->and($result->output)->toContain(php_uname('m') === 'arm64' ? 'Apple Silicon' : 'Intel');
});

it('finds the repo on a first run, reports what is not linked, and saves no settings', function () {
    $result = runBinary($this->home, ['sync', '--checks=symlinks', '--no-interaction']);

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain("{$this->home}/.zshrc")
        ->and($result->output)->toContain('not-linked')
        ->and(file_exists("{$this->home}/.config/dotsnitch"))->toBeFalse();
});

it('dry-runs Homebrew and symlinks against the sample repo, exits 0 and changes nothing', function () {
    $result = runBinary($this->home, ['sync', "--repo={$this->repo}", '--checks=brew,symlinks', '--dry-run', '--no-interaction']);

    expect($result->exitCode)->toBe(0)
        ->and($result->output)->toContain('dotsnitch-fixture-missing')
        ->and($result->output)->toContain("{$this->home}/.zshrc")
        ->and(file_exists("{$this->home}/.zshrc"))->toBeFalse()
        ->and(file_get_contents("{$this->repo}/Brewfile"))->toBe(file_get_contents(dirname(__DIR__).'/Fixtures/dotfiles/Brewfile'));
});

it('prints the prompt count from the saved report fast', function () {
    runBinary($this->home, ['sync', '--checks=symlinks', '--no-interaction']);
    $runs = array_map(function () {
        $start = hrtime(true);
        $output = runBinary($this->home, ['status', '--quiet'])->output;

        return ['output' => $output, 'seconds' => (hrtime(true) - $start) / 1e9];
    }, range(1, 3));

    // The fastest of three, so one slow spawn on a busy runner doesn't fail it.
    expect(array_unique(array_column($runs, 'output')))->toBe(["1\n"])
        ->and(min(array_column($runs, 'seconds')))->toBeLessThan(QUIET_BUDGET_SECONDS);
});

it('refreshes a stale report in the background', function () {
    runBinary($this->home, ['sync', '--checks=symlinks', '--no-interaction']);
    $report = "{$this->home}/.local/state/dotsnitch/last-report.json";
    touch($report, time() - 60);
    $before = modifiedAt($report);

    // --max-age=0 makes the report stale, so the binary has to start itself again.
    runBinary($this->home, ['status', '--quiet', '--max-age=0', '--checks=symlinks']);

    for ($tries = 0; $tries < 50 && modifiedAt($report) === $before; $tries++) {
        usleep(200_000);
    }

    expect(modifiedAt($report))->not->toBe($before);
});
