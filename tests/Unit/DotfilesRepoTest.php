<?php

declare(strict_types=1);

use App\Support\DotfilesRepo;
use App\Support\UserSettings;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    mkdir("{$this->dir}/home/dots", recursive: true);
    $this->home = realpath("{$this->dir}/home");
    $this->settings = new UserSettings("{$this->dir}/config.json");
    $this->repo = new DotfilesRepo($this->settings, ['HOME' => $this->home]);
    $this->cwd = getcwd();
    Prompt::interactive(false);
});

afterEach(function () {
    chdir($this->cwd);
    Prompt::interactive(true);
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('uses an absolute --repo= path without saving it', function () {
    expect($this->repo->resolve("{$this->home}/dots"))->toBe("{$this->home}/dots")
        ->and($this->settings->get('repo'))->toBeNull();
});

it('expands ~ in --repo=', function () {
    expect($this->repo->resolve('~/dots'))->toBe("{$this->home}/dots");
});

it('resolves a relative --repo= against the current directory', function () {
    chdir($this->home);

    expect($this->repo->resolve('dots'))->toBe("{$this->home}/dots");
});

it('rejects a --repo= that is not a directory', function () {
    $this->repo->resolve('~/missing');
})->throws(RuntimeException::class, "--repo= points at ~/missing, which isn't a directory.");

it('saves --repo= when the user says yes', function () {
    Prompt::interactive(true);
    Prompt::fake(['y', Key::ENTER]);

    $this->repo->resolve('~/dots');

    expect($this->settings->get('repo'))->toBe("{$this->home}/dots");
});

it('prefers DOTSNITCH_REPO over the saved path', function () {
    mkdir("{$this->home}/other");
    $this->settings->set('repo', "{$this->home}/dots");
    $repo = new DotfilesRepo($this->settings, ['HOME' => $this->home, 'DOTSNITCH_REPO' => '~/other']);

    expect($repo->resolve(null))->toBe("{$this->home}/other");
});

it('finds the git repo that most home symlinks point into', function () {
    foreach (['setup/.git', 'setup/zsh', 'setup/git', 'other/.git', 'loose'] as $path) {
        mkdir("{$this->home}/{$path}", recursive: true);
    }
    touch("{$this->home}/setup/zsh/zshrc");
    symlink("{$this->home}/setup/zsh/zshrc", "{$this->home}/.zshrc");
    mkdir("{$this->home}/.config");
    symlink("{$this->home}/setup/git", "{$this->home}/.config/git");
    symlink("{$this->home}/other", "{$this->home}/.other");
    symlink("{$this->home}/loose", "{$this->home}/.loose");
    symlink("{$this->home}/gone", "{$this->home}/.broken");

    expect($this->repo->resolve(null))->toBe("{$this->home}/setup");
});

it('reads XDG_CONFIG_HOME for config symlinks', function () {
    mkdir("{$this->home}/setup/.git", recursive: true);
    mkdir("{$this->home}/xdg");
    symlink("{$this->home}/setup", "{$this->home}/xdg/nvim");
    $repo = new DotfilesRepo($this->settings, ['HOME' => $this->home, 'XDG_CONFIG_HOME' => "{$this->home}/xdg"]);

    expect($repo->resolve(null))->toBe("{$this->home}/setup");
});

it('reads ~/.config when XDG_CONFIG_HOME is set but empty', function () {
    mkdir("{$this->home}/setup/.git", recursive: true);
    mkdir("{$this->home}/.config");
    symlink("{$this->home}/setup", "{$this->home}/.config/nvim");
    $repo = new DotfilesRepo($this->settings, ['HOME' => $this->home, 'XDG_CONFIG_HOME' => '']);

    expect($repo->resolve(null))->toBe("{$this->home}/setup");
});

it('remembers the resolved directory for root()', function () {
    $this->repo->resolve('~/dots');

    expect($this->repo->root())->toBe("{$this->home}/dots");
});

it("won't give a root before resolve() runs", function () {
    $this->repo->root();
})->throws(RuntimeException::class, "The dotfiles directory isn't resolved yet.");

it('says how to point at the repo when nothing finds it and there is no terminal', function () {
    $this->repo->resolve(null);
})->throws(RuntimeException::class, "Couldn't find your dotfiles directory. Pass --repo= or set DOTSNITCH_REPO.");
