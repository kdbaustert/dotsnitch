<?php

declare(strict_types=1);

use App\Support\ManifestEditor;
use App\Support\PathResolver;
use App\Targets\MacDefaults;
use Laravel\Prompts\Prompt;
use Tests\Fakes\FakeShell;

const CAPTURE_BEFORE = <<<'DUMP'
    {
        "com.apple.finder" =     {
            ShowPathbar = 0;
        };
    }
    DUMP;

const CAPTURE_AFTER = <<<'DUMP'
    {
        "com.apple.dock" =     {
            autohide = 1;
        };
        "com.apple.finder" =     {
            ShowPathbar = 1;
        };
    }
    DUMP;

// A stand-in `defaults` on fakeMac's PATH, and a shell that answers for it.
beforeEach(function () {
    fakeMac($this);
    $this->defaults = "{$this->dir}/brew/bin/defaults";
    file_put_contents($this->defaults, "#!/bin/sh\n");
    chmod($this->defaults, 0755);
    $this->script = "{$this->dir}/repo/macos.sh";
    file_put_contents($this->script, "defaults write com.apple.dock tilesize -int 48\ndefaults write com.apple.finder ShowPathbar -bool false\n");

    $this->shell = new FakeShell;
    app()->instance(MacDefaults::class, new MacDefaults($this->shell, app(PathResolver::class), app(ManifestEditor::class), ['HOME' => $this->dir], "{$this->dir}/state/defaults-baseline.json"));
});

afterEach(function () {
    // Prompts keeps its config in statics, so the fallback switched on below would reach later tests.
    Prompt::interactive(false);
    (new ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * The reads capture makes for the two settings CAPTURE_AFTER changes.
 */
function expectCapturedReads(FakeShell $shell, string $defaults): void
{
    $shell
        ->expectInTurn([$defaults, 'read'], CAPTURE_BEFORE, CAPTURE_AFTER)
        ->expect([$defaults, 'read-type', 'com.apple.dock', 'autohide'], "Type is boolean\n")
        ->expect([$defaults, 'read', 'com.apple.dock', 'autohide'], "1\n")
        ->expect([$defaults, 'read-type', 'com.apple.finder', 'ShowPathbar'], "Type is boolean\n")
        ->expect([$defaults, 'read', 'com.apple.finder', 'ShowPathbar'], "1\n");
}

it('adds a new setting and changes the line of one the script writes', function () {
    expectCapturedReads($this->shell, $this->defaults);
    app()['env'] = 'testing';

    $this->artisan('capture', ['--repo' => "{$this->dir}/repo"])
        ->expectsQuestion('Change the settings you want to keep, then press Enter.', '')
        ->expectsQuestion('Which settings should go in your dotfiles?', ['com.apple.dock:autohide', 'com.apple.finder:ShowPathbar'])
        ->expectsOutputToContain('Change macos.sh to match')
        ->expectsOutputToContain('Add com.apple.dock:autohide to macos.sh')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->expectsOutputToContain('Applied 2 changes.')
        ->assertExitCode(0);

    expect(file_get_contents($this->script))->toBe(implode("\n", [
        'defaults write com.apple.dock tilesize -int 48',
        'defaults write com.apple.dock autohide -bool true',
        'defaults write com.apple.finder ShowPathbar -bool true',
    ])."\n");
});

it('only shows the steps on a dry run', function () {
    expectCapturedReads($this->shell, $this->defaults);
    app()['env'] = 'testing';

    $this->artisan('capture', ['--repo' => "{$this->dir}/repo", '--dry-run' => true])
        ->expectsQuestion('Change the settings you want to keep, then press Enter.', '')
        ->expectsQuestion('Which settings should go in your dotfiles?', ['com.apple.dock:autohide'])
        ->expectsOutputToContain('Dry run. These would run, in order:')
        ->expectsOutputToContain('Add com.apple.dock:autohide to macos.sh')
        ->assertExitCode(0);

    expect(file_get_contents($this->script))->toBe("defaults write com.apple.dock tilesize -int 48\ndefaults write com.apple.finder ShowPathbar -bool false\n");
});

it('lists every setting as it is now with --current, without waiting', function () {
    $this->shell
        ->expect([$this->defaults, 'read'], str_replace('autohide = 1;', "autohide = 1;\n        \"persistent-apps\" =         (\n            a\n        );", CAPTURE_AFTER))
        ->expect([$this->defaults, 'read-type', 'com.apple.dock', 'persistent-apps'], "Type is array\n")
        ->expect([$this->defaults, 'read-type', 'com.apple.dock', 'autohide'], "Type is boolean\n")
        ->expect([$this->defaults, 'read', 'com.apple.dock', 'autohide'], "1\n")
        ->expect([$this->defaults, 'read-type', 'com.apple.finder', 'ShowPathbar'], "Type is boolean\n")
        ->expect([$this->defaults, 'read', 'com.apple.finder', 'ShowPathbar'], "1\n");
    app()['env'] = 'testing';

    $this->artisan('capture', ['--repo' => "{$this->dir}/repo", '--current' => true])
        ->expectsOutputToContain("1 setting has a type one line can't write: com.apple.dock:persistent-apps (array)")
        ->expectsQuestion('Which settings should go in your dotfiles?', ['com.apple.dock:autohide'])
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->expectsOutputToContain('Applied 1 change.')
        ->assertExitCode(0);

    expect(file_get_contents($this->script))->toBe(implode("\n", [
        'defaults write com.apple.dock tilesize -int 48',
        'defaults write com.apple.dock autohide -bool true',
        'defaults write com.apple.finder ShowPathbar -bool false',
    ])."\n")
        ->and(array_filter($this->shell->ran(), fn (array $command) => $command === [$this->defaults, 'read']))->toHaveCount(1);
});

it('says so when no setting changed', function () {
    $this->shell->expect([$this->defaults, 'read'], CAPTURE_BEFORE);
    app()['env'] = 'testing';

    $this->artisan('capture', ['--repo' => "{$this->dir}/repo"])
        ->expectsQuestion('Change the settings you want to keep, then press Enter.', '')
        ->expectsOutputToContain('No setting changed that one line can write.')
        ->assertExitCode(0);
});

it('fails without a terminal, since there is nobody to wait for', function () {
    $this->artisan('capture', ['--repo' => "{$this->dir}/repo", '--no-interaction' => true])
        ->expectsOutputToContain('Run it in a terminal.')
        ->assertExitCode(1);

    expect($this->shell->ran())->toBe([]);
});
