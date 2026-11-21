<?php

declare(strict_types=1);

use App\Support\ManifestLocator;
use App\Support\Manifests;
use App\Support\UserSettings;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

const CHECKS = [
    'brew' => ['label' => 'Homebrew', 'patterns' => ['Brewfile', '.Brewfile']],
    'composer' => ['label' => 'Composer', 'patterns' => ['composer/composer.json']],
    'symlinks' => ['label' => 'Symlinks', 'patterns' => ['*.symlink'], 'many' => true],
];

/**
 * Creates an empty file at each path under $root, along with any missing directories.
 *
 * @param  list<string>  $paths
 */
function makeFiles(string $root, array $paths): void
{
    foreach ($paths as $path) {
        $directory = dirname("{$root}/{$path}");
        is_dir($directory) || mkdir($directory, recursive: true);
        touch("{$root}/{$path}");
    }
}

/**
 * Turns the checks config into what ManifestLocator::locate() takes.
 *
 * @return array<string, list<string>>
 */
function patternsOf(array $checks): array
{
    return array_map(fn (array $check) => $check['patterns'], $checks);
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    $this->repo = "{$this->dir}/repo";
    $this->settings = new UserSettings("{$this->dir}/config.json");
    $this->manifests = new Manifests($this->settings, CHECKS);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('sorts matches into bases, host overlays and variants', function () {
    makeFiles($this->repo, [
        'homebrew/Brewfile',
        'homebrew/Brewfile.kennys-mbp',
        'homebrew/Brewfile.work',
        'homebrew/Brewfile.lock.json',
        'notes.txt',
    ]);

    $brew = (new ManifestLocator('Kennys-MBP'))->locate($this->repo, patternsOf(CHECKS))['brew'];

    expect($brew->bases)->toBe(['homebrew/Brewfile'])
        ->and($brew->hostOverlays)->toBe(['homebrew/Brewfile.kennys-mbp'])
        ->and($brew->variants)->toBe(['homebrew/Brewfile.work']);
});

it('matches parent/name patterns only inside that directory', function () {
    makeFiles($this->repo, ['composer/composer.json', 'tools/composer.json']);

    $composer = (new ManifestLocator('mac'))->locate($this->repo, patternsOf(CHECKS))['composer'];

    expect($composer->bases)->toBe(['composer/composer.json']);
});

it('skips node_modules, vendor and .git', function () {
    makeFiles($this->repo, ['node_modules/x/Brewfile', 'vendor/Brewfile', '.git/Brewfile', '.Brewfile']);

    $brew = (new ManifestLocator('mac'))->locate($this->repo, patternsOf(CHECKS))['brew'];

    expect($brew->bases)->toBe(['.Brewfile']);
});

it('uses every match for a check that reads many files', function () {
    makeFiles($this->repo, ['zsh/zshrc.symlink', 'git/gitconfig.symlink']);

    expect($this->manifests->chosen($this->repo, 'mac')['symlinks'])
        ->toBe(['git/gitconfig.symlink', 'zsh/zshrc.symlink']);
});

it('asks which base to use once, then remembers', function () {
    makeFiles($this->repo, ['Brewfile', 'homebrew/Brewfile']);
    Prompt::fake([Key::DOWN, Key::ENTER]);

    expect($this->manifests->chosen($this->repo, 'mac')['brew'])->toBe(['homebrew/Brewfile']);

    Prompt::fake([]);

    expect($this->manifests->chosen($this->repo, 'mac')['brew'])->toBe(['homebrew/Brewfile']);
});

it('remembers declining every variant', function () {
    makeFiles($this->repo, ['Brewfile', 'Brewfile.work']);
    Prompt::fake([Key::ENTER]);

    expect($this->manifests->chosen($this->repo, 'mac')['brew'])->toBe(['Brewfile'])
        ->and($this->settings->get('manifests.brew.variants'))->toBe([]);
});

it('reads a picked variant after the base and host overlay', function () {
    makeFiles($this->repo, ['Brewfile', 'Brewfile.mac', 'Brewfile.work']);
    Prompt::fake([Key::SPACE, Key::ENTER]);

    expect($this->manifests->chosen($this->repo, 'mac')['brew'])
        ->toBe(['Brewfile', 'Brewfile.mac', 'Brewfile.work']);
});

it('only looks at the checks it was narrowed to', function () {
    makeFiles($this->repo, ['homebrew/Brewfile', 'composer.json']);

    expect($this->manifests->only(['brew'])->chosen($this->repo, 'mac'))->toBe(['brew' => ['homebrew/Brewfile']]);
});

it('takes the first base and no variants without a terminal, and saves neither', function () {
    makeFiles($this->repo, ['Brewfile', 'homebrew/Brewfile', 'Brewfile.work']);
    Prompt::fake([]);

    expect($this->manifests->withDefaults()->only(['brew'])->chosen($this->repo, 'mac'))->toBe(['brew' => ['Brewfile']])
        ->and($this->settings->get('manifests'))->toBeNull();

    Prompt::assertOutputContains('Homebrew reads Brewfile this run.');
    Prompt::assertOutputContains('Homebrew skips Brewfile.work this run.');
});
