<?php

declare(strict_types=1);

use App\Support\PathResolver;
use App\Support\UserSettings;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Targets\MacDefaults;
use Tests\Fakes\FakeShell;

// A stand-in `defaults` on PATH, so the commands are the same on any machine and never run.
beforeEach(function () {
    $dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    mkdir("{$dir}/bin", 0755, true);
    $this->dir = realpath($dir);
    $this->defaults = "{$this->dir}/bin/defaults";
    file_put_contents($this->defaults, "#!/bin/sh\n");
    chmod($this->defaults, 0755);
    $this->script = "{$this->dir}/macos.sh";
    $this->logged = [];

    $settings = new UserSettings("{$this->dir}/config.json");
    // An empty prefix, so PathResolver doesn't go looking through the real Homebrew.
    mkdir("{$this->dir}/brew/bin", 0755, true);
    file_put_contents("{$this->dir}/brew/bin/brew", "#!/bin/sh\n");
    chmod("{$this->dir}/brew/bin/brew", 0755);
    $settings->set('homebrew.prefix', "{$this->dir}/brew");
    $inherited = ['SHELL' => '/bin/zsh', 'HOME' => '/Users/sam', 'PATH' => "{$this->dir}/bin"];
    $login = (new FakeShell)->expect(['/bin/zsh', '+m', '-lic', 'printf __DOTSNITCH_ENV__; /usr/bin/env -0'], exitCode: 1);
    $this->shell = new FakeShell;
    $this->check = new MacDefaults($this->shell, new PathResolver($login, $settings, $inherited), $inherited);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * Writes the script, then scans it.
 *
 * @return list<DriftItem>
 */
function scanDefaults(object $test, string ...$lines): array
{
    file_put_contents($test->script, implode("\n", $lines)."\n");

    return $test->check->scan([$test->script], function (string $line) use ($test) {
        $test->logged[] = $line;
    })->items;
}

it('reports nothing when every setting already has the value the script writes', function () {
    $this->shell
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'autohide'], "1\n")
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'tilesize'], "48\n")
        ->expect([$this->defaults, 'read', 'NSGlobalDomain', 'AppleInterfaceStyle'], "Dark\n")
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'magnification-ratio'], "1.5\n")
        ->expect([$this->defaults, 'read', 'com.apple.finder', 'ShowPathbar'], "0\n");

    $items = scanDefaults($this,
        '#!/bin/sh',
        'defaults write com.apple.dock autohide -bool true',
        '  defaults write com.apple.dock tilesize -int 48  # smaller icons',
        'defaults write -g AppleInterfaceStyle -string "Dark"',
        'defaults write com.apple.dock magnification-ratio -float 1.50',
        'defaults write com.apple.finder ShowPathbar -bool NO',
    );

    expect($items)->toBe([])->and($this->logged)->toBe([]);
});

it('reports a setting with another value, and one that is not set', function () {
    $this->shell
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'tilesize'], "64\n")
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'autohide'], exitCode: 1, errorOutput: 'does not exist');

    $items = scanDefaults($this,
        'defaults write com.apple.dock tilesize -int 48',
        'defaults write com.apple.dock autohide -bool true',
    );

    expect($items)->toHaveCount(2)
        ->and($items[0]->kind)->toBe(DriftKind::Differs)
        ->and($items[0]->subject->key())->toBe('com.apple.dock:tilesize')
        ->and($items[0]->detail)->toBe('is 64, macos.sh writes 48')
        ->and($items[1]->detail)->toBe("isn't set, macos.sh writes true")
        ->and(array_keys($this->check->actionsFor($items[0])))->toBe(['write', 'ignore', 'skip']);
});

it('fills in $HOME, takes the quotes off, and lets the last line for a key win', function () {
    $this->shell
        ->expect([$this->defaults, 'read', 'com.apple.finder', 'NewWindowTargetPath'], "file:///Users/sam/\n")
        ->expect([$this->defaults, 'read', 'com.apple.screencapture', 'location'], "/Users/sam/Shots\n")
        ->expect([$this->defaults, 'read', 'com.apple.screencapture', 'name'], "My Shot\n");

    $items = scanDefaults($this,
        'defaults write com.apple.finder NewWindowTargetPath -string "file://${HOME}/"',
        'defaults write com.apple.screencapture location -string "/tmp"',
        'defaults write com.apple.screencapture location -string "$HOME/Shots"',
        "defaults write com.apple.screencapture name 'My Shot'",
    );

    expect($items)->toBe([]);
});

it('fills in an unquoted ~, and leaves a quoted one as it is', function () {
    $this->shell
        ->expect([$this->defaults, 'read', 'com.apple.screencapture', 'location'], "/Users/sam/Shots\n")
        ->expect([$this->defaults, 'read', 'com.apple.screencapture', 'name'], "~/Shots\n");

    $items = scanDefaults($this,
        'defaults write com.apple.screencapture location ~/Shots',
        'defaults write com.apple.screencapture name -string "~/Shots"',
    );

    expect($items)->toBe([]);
});

it('goes by a later file\'s line for a key an earlier file writes too', function () {
    $overlay = "{$this->script}.work";
    file_put_contents($this->script, "defaults write com.apple.dock tilesize -int 33\n");
    file_put_contents($overlay, "defaults write com.apple.dock tilesize -int 40\n");
    $this->shell->expect([$this->defaults, 'read', 'com.apple.dock', 'tilesize'], "40\n");

    expect($this->check->scan([$this->script, $overlay], fn () => null)->items)->toBe([]);
});

it('passes over the lines it cannot compare, and says how many', function () {
    $items = scanDefaults($this,
        'defaults write com.apple.dock persistent-apps -array',
        "defaults write com.apple.dock persistent-others '(a, b)'",
        'defaults write com.apple.dock tilesize ~sam/size',
        'defaults write com.apple.dock wvous-corners -array 4 5',
        'defaults write com.apple.dock tilesize -int "$SIZE"',
        'defaults write com.apple.dock autohide -bool true && killall Dock',
        'defaults write -currentHost com.apple.screensaver idleTime -int 0',
        'sudo defaults write /Library/Preferences/com.apple.loginwindow SHOWFULLNAME -bool true',
        'echo defaults write is fun',
    );

    expect($items)->toBe([])
        ->and($this->shell->ran())->toBe([])
        ->and($this->logged)->toBe(["7 defaults write lines in macos.sh can't be compared"]);
});

it('writes a setting, and records how to undo it only when it had no value', function () {
    $this->shell
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'tilesize'], "64\n")
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'autohide'], exitCode: 1)
        ->expect([$this->defaults, 'write', 'com.apple.dock', 'tilesize', '-int', '48'])
        ->expect([$this->defaults, 'write', 'com.apple.dock', 'autohide', '-bool', 'true']);

    $items = scanDefaults($this,
        'defaults write com.apple.dock tilesize -int 48',
        'defaults write com.apple.dock autohide -bool true',
    );
    [$changed] = $this->check->steps(new Decision($items[0], Action::Write));
    [$added] = $this->check->steps(new Decision($items[1], Action::Write));

    expect($changed->description)->toBe("{$this->defaults} write com.apple.dock tilesize -int 48")
        ->and(($changed->run)())->toBe(['kind' => 'command', 'command' => [$this->defaults, 'write', 'com.apple.dock', 'tilesize', '-int', '48']])
        ->and(($added->run)())->toBe([
            'kind' => 'command',
            'command' => [$this->defaults, 'write', 'com.apple.dock', 'autohide', '-bool', 'true'],
            'undo' => [[$this->defaults, 'delete', 'com.apple.dock', 'autohide']],
            'environment' => [],
        ]);
});

it('fails the step when defaults write does', function () {
    $this->shell
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'tilesize'], "64\n")
        ->expect([$this->defaults, 'write', 'com.apple.dock', 'tilesize', '-int', '48'], exitCode: 1, errorOutput: "Could not write domain\n");

    $items = scanDefaults($this, 'defaults write com.apple.dock tilesize -int 48');
    [$write] = $this->check->steps(new Decision($items[0], Action::Write));

    expect(fn () => ($write->run)())->toThrow(RuntimeException::class, 'Could not write domain');
});
