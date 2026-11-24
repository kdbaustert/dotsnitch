<?php

declare(strict_types=1);

use App\Data\DefaultsSnapshot;
use App\Support\ManifestEditor;
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
    // Every scan reads SYSTEM_DOMAINS too. A test that cares sets what it prints.
    $this->shell = (new FakeShell)->expect([$this->defaults, 'read']);
    $this->baseline = "{$this->dir}/state/defaults-baseline.json";
    $this->check = new MacDefaults($this->shell, new PathResolver($login, $settings, $inherited), new ManifestEditor("{$this->dir}/backups"), $inherited, $this->baseline);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * Runs every step of each decision in order.
 *
 * @param  list<Decision>  $decisions
 * @return list<string> the step descriptions
 */
function runDefaultsSteps(object $test, array $decisions): array
{
    $descriptions = [];

    foreach ($decisions as $decision) {
        foreach ($test->check->steps($decision) as $step) {
            $descriptions[] = $step->description;
            ($step->run)();
        }
    }

    return $descriptions;
}

/**
 * Writes the script, then captures what changed between the two dumps.
 *
 * @param  list<string>  $lines
 * @return list<DriftItem>
 */
function captureDefaults(object $test, array $lines, string $before, string $after): array
{
    file_put_contents($test->script, implode("\n", $lines)."\n");

    return $test->check->capture(DefaultsSnapshot::parse($before), DefaultsSnapshot::parse($after), [$test->script], function (string $line) use ($test) {
        $test->logged[] = $line;
    })->items;
}

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
        ->and(array_keys($this->check->actionsFor($items[0])))->toBe(['write', 'update', 'ignore', 'skip'])
        ->and($this->check->actionsFor($items[0])['update'])->toBe('Change macos.sh to match')
        ->and(array_keys($this->check->actionsFor($items[1])))->toBe(['write', 'ignore', 'skip']);
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

it('undoes only the backslashes the shell undoes inside double quotes', function () {
    $this->shell
        ->expect([$this->defaults, 'read', 'com.example.app', 'folder'], "C:\\new\\temp\n")
        ->expect([$this->defaults, 'read', 'com.example.app', 'greeting'], "say \"hi\" \\o/\n");

    $items = scanDefaults($this,
        'defaults write com.example.app folder -string "C:\new\temp"',
        'defaults write com.example.app greeting -string "say \"hi\" \\\\o/"',
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
        ->and($this->shell->ran())->toBe([[$this->defaults, 'read']])
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

it('changes the line to the value this Mac has, and leaves the rest of it alone', function () {
    $this->shell
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'tilesize'], "64\n")
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'autohide'], "0\n")
        ->expect([$this->defaults, 'read', 'com.apple.screencapture', 'name'], "My Shot\n")
        ->expect([$this->defaults, 'read', 'com.apple.screencapture', 'type'], "It's \"here\"\n");

    $items = scanDefaults($this,
        '#!/bin/sh',
        '  defaults write com.apple.dock tilesize -int 48  # smaller icons',
        'defaults write com.apple.dock autohide -bool true',
        'defaults write com.apple.screencapture name "Shot"',
        'defaults write com.apple.screencapture type -string png',
    );
    $steps = runDefaultsSteps($this, array_map(fn (DriftItem $item) => new Decision($item, Action::UpdateManifest), $items));

    expect($steps)->toBe([
        'Change com.apple.dock:tilesize to 64 in macos.sh',
        'Change com.apple.dock:autohide to false in macos.sh',
        "Change com.apple.screencapture:name to 'My Shot' in macos.sh",
        'Change com.apple.screencapture:type to "It\'s \"here\"" in macos.sh',
    ])
        ->and(file_get_contents($this->script))->toBe(implode("\n", [
            '#!/bin/sh',
            '  defaults write com.apple.dock tilesize -int 64  # smaller icons',
            'defaults write com.apple.dock autohide -bool false',
            "defaults write com.apple.screencapture name 'My Shot'",
            'defaults write com.apple.screencapture type -string "It\'s \"here\""',
        ])."\n")
        ->and($this->check->scan([$this->script], fn () => null)->items)->toBe([]);
});

it('refuses to change a line that changed since the scan, or a value the script could not quote', function () {
    $this->shell
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'tilesize'], "64\n")
        ->expect([$this->defaults, 'read', 'com.apple.screencapture', 'name'], "it's \$5\n");

    $items = scanDefaults($this,
        'defaults write com.apple.dock tilesize -int 48',
        'defaults write com.apple.screencapture name -string Shot',
    );
    [$update] = $this->check->steps(new Decision($items[0], Action::UpdateManifest));
    file_put_contents($this->script, "defaults write com.apple.dock tilesize -int 50\n");

    expect(fn () => ($update->run)())->toThrow(RuntimeException::class, 'macos.sh changed since the scan')
        ->and(fn () => $this->check->steps(new Decision($items[1], Action::UpdateManifest)))
        ->toThrow(RuntimeException::class, 'so the script can\'t quote it');
});

it('captures the settings that changed and that one line can write', function () {
    $before = <<<'DUMP'
        {
            "Apple Global Domain" =     {
                AppleInterfaceStyle = Dark;
            };
            "com.apple.dock" =     {
                autohide = 0;
                "persistent-apps" =         (
                    a
                );
                tilesize = 48;
            };
        }
        DUMP;
    $after = <<<'DUMP'
        {
            "Apple Global Domain" =     {
                KeyRepeat = 2;
            };
            "com.apple.dock" =     {
                autohide = 1;
                "persistent-apps" =         (
                    a,
                    b
                );
                tilesize = 64;
            };
            "com.apple.finder" =     {
                ShowPathbar = 1;
            };
            "com.apple.screencapture" =     {
                name = "My Shot";
            };
        }
        DUMP;
    $this->shell
        ->expect([$this->defaults, 'read-type', 'NSGlobalDomain', 'KeyRepeat'], "Type is integer\n")
        ->expect([$this->defaults, 'read', 'NSGlobalDomain', 'KeyRepeat'], "2\n")
        ->expect([$this->defaults, 'read-type', 'com.apple.dock', 'autohide'], "Type is boolean\n")
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'autohide'], "1\n")
        ->expect([$this->defaults, 'read-type', 'com.apple.dock', 'persistent-apps'], "Type is array\n")
        ->expect([$this->defaults, 'read-type', 'com.apple.dock', 'tilesize'], "Type is integer\n")
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'tilesize'], "64\n")
        ->expect([$this->defaults, 'read-type', 'com.apple.finder', 'ShowPathbar'], "Type is boolean\n")
        ->expect([$this->defaults, 'read', 'com.apple.finder', 'ShowPathbar'], "1\n")
        ->expect([$this->defaults, 'read-type', 'com.apple.screencapture', 'name'], "Type is string\n")
        ->expect([$this->defaults, 'read', 'com.apple.screencapture', 'name'], "My Shot\n");

    $items = captureDefaults($this, [
        'defaults write com.apple.dock autohide -bool true',
        'defaults write com.apple.dock tilesize -int 48',
    ], $before, $after);

    expect(array_map(fn (DriftItem $item) => [$item->kind, $item->subject->key(), $item->detail], $items))->toBe([
        [DriftKind::Untracked, 'NSGlobalDomain:KeyRepeat', '-int 2'],
        [DriftKind::Differs, 'com.apple.dock:tilesize', 'is 64, macos.sh writes 48'],
        [DriftKind::Untracked, 'com.apple.finder:ShowPathbar', '-bool true'],
        [DriftKind::Untracked, 'com.apple.screencapture:name', '-string My Shot'],
    ])
        ->and(array_keys($this->check->actionsFor($items[0])))->toBe(['add', 'ignore', 'skip'])
        ->and(array_keys($this->check->actionsFor($items[1])))->toBe(['write', 'update', 'ignore', 'skip'])
        ->and($this->logged)->toBe(["1 setting has a type one line can't write: com.apple.dock:persistent-apps (array)"]);
});

it('reads only what System Settings, the Dock, Finder and the menu bar save, and not their counters', function () {
    $this->shell->expect([$this->defaults, 'read'], <<<'DUMP'
        {
            "com.apple.DuetExpertCenter.MagicalMoments" =     {
                lastRun = 42;
            };
            "com.apple.Terminal" =     {
                "Default Window Settings" = Basic;
            };
            "com.apple.dock" =     {
                autohide = 1;
                lastShowIndicatorTime = "814606546.085025";
                "mod-count" = 245;
            };
            "com.google.Chrome" =     {
                LastRunAppBundlePath = "/Applications/Google Chrome.app";
            };
            "iordv.Droppy" =     {
                isShelfOpen = 1;
            };
        }
        DUMP);

    expect($this->check->snapshot()->settings)->toBe(['com.apple.dock' => ['autohide' => '1;']]);
});

it('names the settings one line cannot write only when there are a few', function () {
    $after = "{\n    \"com.apple.dock\" =     {\n";

    foreach (range(1, 6) as $n) {
        $after .= "        \"tile-{$n}\" =         (\n            a\n        );\n";
        $this->shell->expect([$this->defaults, 'read-type', 'com.apple.dock', "tile-{$n}"], "Type is array\n");
    }

    captureDefaults($this, [], '', "{$after}    };\n}\n");

    expect($this->logged)->toBe(["6 settings have a type one line can't write"]);
});

it('saves a baseline on the first scan, and reports nothing it does not write', function () {
    $this->shell
        ->expect([$this->defaults, 'read'], "{\n    \"com.apple.dock\" =     {\n        autohide = 1;\n    };\n}\n");

    expect(scanDefaults($this))->toBe([])
        ->and(json_decode((string) file_get_contents($this->baseline), true))->toBe(['com.apple.dock' => ['autohide' => '1;']]);
});

it('reports a setting that changed since the baseline and that no line writes', function () {
    file_put_contents($this->script, "defaults write com.apple.dock tilesize -int 48\n");
    mkdir(dirname($this->baseline));
    file_put_contents($this->baseline, json_encode(['com.apple.dock' => ['autohide' => '0;', 'tilesize' => '48;', 'orientation' => 'left;']]));
    $this->shell
        ->expect([$this->defaults, 'read'], <<<'DUMP'
            {
                "com.apple.dock" =     {
                    autohide = 1;
                    orientation = left;
                    tilesize = 64;
                };
                "com.apple.finder" =     {
                    ShowPathbar = 1;
                };
            }
            DUMP)
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'tilesize'], "64\n")
        ->expect([$this->defaults, 'read-type', 'com.apple.dock', 'autohide'], "Type is boolean\n")
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'autohide'], "1\n")
        ->expect([$this->defaults, 'read-type', 'com.apple.finder', 'ShowPathbar'], "Type is boolean\n")
        ->expect([$this->defaults, 'read', 'com.apple.finder', 'ShowPathbar'], "1\n");

    $items = $this->check->scan([$this->script], fn () => null)->items;

    expect(array_map(fn (DriftItem $item) => [$item->kind, $item->subject->key(), $item->detail], $items))->toBe([
        [DriftKind::Differs, 'com.apple.dock:tilesize', 'is 64, macos.sh writes 48'],
        [DriftKind::Untracked, 'com.apple.dock:autohide', '-bool true'],
        [DriftKind::Untracked, 'com.apple.finder:ShowPathbar', '-bool true'],
    ])
        ->and(array_keys($this->check->actionsFor($items[1])))->toBe(['add', 'ignore', 'skip'])
        ->and(runDefaultsSteps($this, [new Decision($items[1], Action::AddToManifest, [$this->script])]))->toBe(['Add com.apple.dock:autohide to macos.sh'])
        ->and(json_decode((string) file_get_contents($this->baseline), true))->toBe(['com.apple.dock' => ['autohide' => '0;', 'tilesize' => '48;', 'orientation' => 'left;']]);
});

it('saves a new baseline over one it cannot read', function () {
    mkdir(dirname($this->baseline));
    file_put_contents($this->baseline, '{"com.apple.dock": "autohide"}');
    $this->shell->expect([$this->defaults, 'read'], "{\n    \"com.apple.dock\" =     {\n        autohide = 1;\n    };\n}\n");

    expect(scanDefaults($this))->toBe([])
        ->and(json_decode((string) file_get_contents($this->baseline), true))->toBe(['com.apple.dock' => ['autohide' => '1;']]);
});

it('adds a captured setting after the last line for its domain, or after the last line', function () {
    $this->shell
        ->expect([$this->defaults, 'read-type', 'com.apple.dock', 'mineffect'], "Type is string\n")
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'mineffect'], "scale\n")
        ->expect([$this->defaults, 'read-type', 'NSGlobalDomain', 'Key Repeat'], "Type is float\n")
        ->expect([$this->defaults, 'read', 'NSGlobalDomain', 'Key Repeat'], "1.5\n");

    $items = captureDefaults($this, [
        '#!/bin/sh',
        '',
        '# Dock',
        '  defaults write com.apple.dock tilesize -int 48',
        '  defaults write com.apple.dock autohide -bool true',
        '',
        'defaults write com.apple.finder AppleShowAllFiles -bool true',
        '',
        'killall Dock',
    ], "{\n}\n", <<<'DUMP'
        {
            "com.apple.dock" =     {
                mineffect = scale;
            };
            "Apple Global Domain" =     {
                "Key Repeat" = "1.5";
            };
        }
        DUMP);
    $steps = runDefaultsSteps($this, array_map(fn (DriftItem $item) => new Decision($item, Action::AddToManifest, [$this->script]), $items));

    expect($steps)->toBe(['Add com.apple.dock:mineffect to macos.sh', 'Add NSGlobalDomain:Key Repeat to macos.sh'])
        ->and(file_get_contents($this->script))->toBe(implode("\n", [
            '#!/bin/sh',
            '',
            '# Dock',
            '  defaults write com.apple.dock tilesize -int 48',
            '  defaults write com.apple.dock autohide -bool true',
            '  defaults write com.apple.dock mineffect -string scale',
            '',
            'defaults write com.apple.finder AppleShowAllFiles -bool true',
            "defaults write NSGlobalDomain 'Key Repeat' -float 1.5",
            '',
            'killall Dock',
        ])."\n");
});

it('starts a new script with a shebang when it adds the first setting', function () {
    $this->shell
        ->expect([$this->defaults, 'read-type', 'com.apple.dock', 'autohide'], "Type is boolean\n")
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'autohide'], "0\n");
    $new = "{$this->dir}/defaults.sh";

    $items = $this->check->capture(
        DefaultsSnapshot::parse(''),
        DefaultsSnapshot::parse("{\n    \"com.apple.dock\" =     {\n        autohide = 0;\n    };\n}\n"),
        [],
        fn () => null,
    )->items;
    runDefaultsSteps($this, [new Decision($items[0], Action::AddToManifest, [$new])]);

    expect(file_get_contents($new))->toBe("#!/usr/bin/env bash\n\ndefaults write com.apple.dock autohide -bool false\n");
});
