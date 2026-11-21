<?php

declare(strict_types=1);

use App\Support\LaunchAgent;
use App\Support\ProcessShell;
use App\Support\SelfPath;
use Tests\Fakes\FakeShell;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-agent-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    $this->service = 'gui/'.posix_getuid().'/'.LaunchAgent::LABEL;
    $this->plist = "{$this->dir}/Library/LaunchAgents/".LaunchAgent::LABEL.'.plist';
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('writes a plist launchd can read, then reloads it', function () {
    $shell = (new FakeShell)
        ->expect(['/bin/launchctl', 'bootout', $this->service], exitCode: 3, errorOutput: 'No such process')
        ->expect(['/bin/launchctl', 'print', $this->service], exitCode: 113)
        ->expect(['/bin/launchctl', 'bootstrap', 'gui/'.posix_getuid(), $this->plist]);
    $terminal = ['SHELL' => '/bin/zsh', 'PATH' => '/opt/homebrew/bin:/usr/bin:/bin', 'TERM' => 'xterm-ghostty'];

    (new LaunchAgent($shell, $this->dir, "{$this->dir}/state/schedule.log", $terminal))->install('7:05', '/Users/me/dot & files');

    // The real plutil reads it back, so a typo in the hand-built XML fails here.
    expect((new LaunchAgent(new ProcessShell, $this->dir, "{$this->dir}/state/schedule.log"))->installed())->toBe([
        'arguments' => [...SelfPath::command(), 'status', '--notify', '--no-interaction'],
        'hour' => 7,
        'minute' => 5,
    ])
        ->and(file_get_contents($this->plist))->toContain('<string>/Users/me/dot &amp; files</string>')
        ->and(file_get_contents($this->plist))->toContain("<key>SHELL</key>\n\t\t<string>/bin/zsh</string>")
        ->and(file_get_contents($this->plist))->toContain("<key>PATH</key>\n\t\t<string>/opt/homebrew/bin:/usr/bin:/bin</string>")
        ->and(file_get_contents($this->plist))->not->toContain('TERM')
        ->and(is_dir("{$this->dir}/state"))->toBeTrue()
        ->and(array_column($shell->ran(), 1))->toBe(['bootout', 'print', 'bootstrap']);
});

it('rejects a time that is not 24-hour HH:MM', function (string $at) {
    (new LaunchAgent(new FakeShell, $this->dir, "{$this->dir}/schedule.log"))->install($at, '/repo');
})->with(['24:00', '9', '09:60', '9am'])->throws(InvalidArgumentException::class, '--at takes a 24-hour time such as 09:00');

it('says why launchctl refused the plist', function () {
    $shell = (new FakeShell)
        ->expect(['/bin/launchctl', 'bootout', $this->service])
        ->expect(['/bin/launchctl', 'print', $this->service], exitCode: 113)
        ->expect(['/bin/launchctl', 'bootstrap', 'gui/'.posix_getuid(), $this->plist], exitCode: 5, errorOutput: "Bootstrap failed: 5: Input/output error\n");

    (new LaunchAgent($shell, $this->dir, "{$this->dir}/schedule.log"))->install('09:00', '/repo');
})->throws(RuntimeException::class, 'launchctl bootstrap failed: Bootstrap failed: 5: Input/output error');

it('unloads and deletes the plist, and says when there was none', function () {
    $shell = (new FakeShell)->expect(['/bin/launchctl', 'bootout', $this->service], exitCode: 3);
    $agent = new LaunchAgent($shell, $this->dir, "{$this->dir}/schedule.log");

    expect($agent->uninstall())->toBeFalse();

    mkdir(dirname($this->plist), 0755, true);
    touch($this->plist);

    expect($agent->uninstall())->toBeTrue()
        ->and(file_exists($this->plist))->toBeFalse();
});

it('reads the last exit code from launchctl print', function () {
    $loaded = (new FakeShell)->expect(['/bin/launchctl', 'print', $this->service], "\tstate = not running\n\tlast exit code = 0\n");
    $unloaded = (new FakeShell)->expect(['/bin/launchctl', 'print', $this->service], exitCode: 113);

    expect((new LaunchAgent($loaded, $this->dir, "{$this->dir}/schedule.log"))->lastExit())->toBe('0')
        ->and((new LaunchAgent($unloaded, $this->dir, "{$this->dir}/schedule.log"))->lastExit())->toBeNull();
});

it('returns the end of the log, skipping blank lines', function () {
    file_put_contents("{$this->dir}/schedule.log", "one\n\ntwo\nthree\n");
    $agent = new LaunchAgent(new FakeShell, $this->dir, "{$this->dir}/schedule.log");

    expect($agent->logTail(2))->toBe(['two', 'three'])
        ->and((new LaunchAgent(new FakeShell, $this->dir, "{$this->dir}/missing.log"))->logTail(2))->toBe([]);
});

it('reads only the end of a long log, and reaches into the rotated one', function () {
    $log = "{$this->dir}/schedule.log";
    $agent = new LaunchAgent(new FakeShell, $this->dir, $log);
    // Longer than the part logTail reads, so that line gets cut and dropped.
    file_put_contents($log, str_repeat('x', 20_000)."\nlast\n");

    expect($agent->logTail(5))->toBe(['last']);

    file_put_contents("{$log}.1", "one\ntwo\n");
    file_put_contents($log, "three\n");

    expect($agent->logTail(2))->toBe(['two', 'three']);
});

it('moves the log aside once it passes 1 MB', function () {
    $log = "{$this->dir}/schedule.log";
    $agent = new LaunchAgent(new FakeShell, $this->dir, $log);
    file_put_contents($log, 'small');
    $agent->rotateLog();

    expect(file_get_contents($log))->toBe('small');

    file_put_contents($log, str_repeat('x', 1_000_001));
    $agent->rotateLog();

    expect(file_exists($log))->toBeFalse()
        ->and(filesize("{$log}.1"))->toBe(1_000_001);
});

it('writes the plist in the snapshot', function () {
    $shell = (new FakeShell)
        ->expect(['/bin/launchctl', 'bootout', $this->service], exitCode: 3)
        ->expect(['/bin/launchctl', 'print', $this->service], exitCode: 113)
        ->expect(['/bin/launchctl', 'bootstrap', 'gui/'.posix_getuid(), $this->plist]);

    (new LaunchAgent($shell, $this->dir, "{$this->dir}/state/schedule.log", ['SHELL' => '/bin/zsh', 'PATH' => '/usr/bin:/bin']))->install('09:00', '/Users/me/dotfiles');
    [$php, $script] = SelfPath::command();

    expect(str_replace([$this->dir, $php, $script], ['{home}', '{php}', '{script}'], file_get_contents($this->plist)))
        ->toBe(file_get_contents(dirname(__DIR__).'/Fixtures/launchd/com.dotsnitch.status.plist'));
});
