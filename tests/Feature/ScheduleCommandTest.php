<?php

declare(strict_types=1);

use App\Support\LaunchAgent;
use Tests\Fakes\FakeShell;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-schedule-'.bin2hex(random_bytes(4));
    mkdir("{$this->dir}/repo", 0755, true);
    $this->service = 'gui/'.posix_getuid().'/'.LaunchAgent::LABEL;
    $this->plist = "{$this->dir}/Library/LaunchAgents/".LaunchAgent::LABEL.'.plist';
    $this->shell = new FakeShell;
    $this->app->instance(LaunchAgent::class, new LaunchAgent($this->shell, $this->dir, "{$this->dir}/schedule.log"));
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * Puts an empty plist at $plist so it counts as installed, and returns what plutil would read
 * from it: $program at 07:30.
 */
function installedPlist(string $plist, string $program): string
{
    mkdir(dirname($plist), 0755, true);
    touch($plist);

    return json_encode([
        'ProgramArguments' => [$program, 'status', '--notify', '--no-interaction'],
        'StartCalendarInterval' => ['Hour' => 7, 'Minute' => 30],
    ]);
}

it('installs the agent for the given repo and time', function () {
    $this->shell
        ->expect(['/bin/launchctl', 'bootout', $this->service], exitCode: 3)
        ->expect(['/bin/launchctl', 'print', $this->service], exitCode: 113)
        ->expect(['/bin/launchctl', 'bootstrap', 'gui/'.posix_getuid(), $this->plist]);

    $this->artisan('schedule', ['--install' => true, '--at' => '07:30', '--repo' => "{$this->dir}/repo"])
        ->expectsOutputToContain('dotsnitch status will run every day at 07:30')
        ->expectsOutputToContain("Output goes to {$this->dir}/schedule.log.")
        ->assertExitCode(0);

    expect(file_get_contents($this->plist))->toContain('<string>'.realpath("{$this->dir}/repo").'</string>');
});

it('says how to turn it on when nothing is installed', function () {
    $this->artisan('schedule')
        ->expectsOutputToContain("The daily run isn't set up. Turn it on with dotsnitch schedule --install.")
        ->assertExitCode(0);
});

it('shows the time, the last exit and the end of the log', function () {
    $this->shell
        ->expect(['/usr/bin/plutil', '-convert', 'json', '-o', '-', $this->plist], installedPlist($this->plist, PHP_BINARY))
        ->expect(['/bin/launchctl', 'print', $this->service], "\tlast exit code = 0\n");
    file_put_contents("{$this->dir}/schedule.log", "1 out of sync\n");

    $this->artisan('schedule', ['--status' => true])
        ->expectsOutputToContain('Runs every day at 07:30, next ')
        ->expectsOutputToContain('launchd: loaded, last exit code 0')
        ->expectsOutputToContain('Command: '.PHP_BINARY.' status --notify --no-interaction')
        ->expectsOutputToContain('1 out of sync')
        ->doesntExpectOutputToContain('no longer exists')
        ->assertExitCode(0);
});

it('warns when the installed dotsnitch has moved and launchd lost it', function () {
    $this->shell
        ->expect(['/usr/bin/plutil', '-convert', 'json', '-o', '-', $this->plist], installedPlist($this->plist, '/gone/dotsnitch'))
        ->expect(['/bin/launchctl', 'print', $this->service], exitCode: 113);

    $this->artisan('schedule')
        ->expectsOutputToContain('launchd: not loaded. Load it again with dotsnitch schedule --install.')
        ->expectsOutputToContain('/gone/dotsnitch no longer exists. Run dotsnitch schedule --install from the dotsnitch you use now.')
        ->assertExitCode(0);
});

it('says how to fix a plist it cannot read', function () {
    installedPlist($this->plist, PHP_BINARY);
    $this->shell->expect(['/usr/bin/plutil', '-convert', 'json', '-o', '-', $this->plist], exitCode: 1, errorOutput: "Unexpected character\n");

    $this->artisan('schedule')
        ->expectsOutputToContain("Couldn't read {$this->plist}: Unexpected character")
        ->expectsOutputToContain('Write it again with dotsnitch schedule --install.')
        ->assertExitCode(1);
});

it('turns the daily run off', function () {
    $this->shell->expect(['/bin/launchctl', 'bootout', $this->service]);
    mkdir(dirname($this->plist), 0755, true);
    touch($this->plist);

    $this->artisan('schedule', ['--uninstall' => true])
        ->expectsOutputToContain('The daily run is off.')
        ->assertExitCode(0);

    expect(file_exists($this->plist))->toBeFalse();
});

it('takes one action at a time', function () {
    $this->artisan('schedule', ['--install' => true, '--uninstall' => true]);
})->throws(InvalidArgumentException::class, 'Pick one of --install, --uninstall and --status.');

it('refuses --at and --repo without --install', function (array $options) {
    $this->artisan('schedule', $options);
})->with([
    [['--at' => '07:00']],
    [['--status' => true, '--repo' => '/repo']],
])->throws(InvalidArgumentException::class, '--at and --repo only go with --install.');
