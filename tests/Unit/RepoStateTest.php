<?php

declare(strict_types=1);

use App\Support\DotfilesRepo;
use App\Support\PathResolver;
use App\Support\ProcessShell;
use App\Support\UserSettings;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Targets\RepoState;
use Laravel\Prompts\Prompt;
use Tests\Fakes\FakeShell;

// Real git, on a repo and a bare remote in a temp folder. Nothing leaves the Mac.
beforeEach(function () {
    $dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $this->dir = realpath($dir);
    $this->root = "{$this->dir}/dotfiles";
    $this->remote = "{$this->dir}/remote.git";

    // Keeps the developer's own git config, and its commit signing, out of the test repos.
    putenv('GIT_CONFIG_GLOBAL=/dev/null');
    putenv('GIT_CONFIG_SYSTEM=/dev/null');

    exec('/usr/bin/git init -q --bare -b main '.escapeshellarg($this->remote));
    exec('/usr/bin/git clone -q '.escapeshellarg($this->remote).' '.escapeshellarg($this->root).' 2>/dev/null');
    commitFile($this->root, 'Brewfile');
    gitIn($this->root, 'push', '-q', '-u', 'origin', 'main');

    Prompt::interactive(false);
    $settings = new UserSettings("{$this->dir}/config.json");
    // An empty prefix, so PathResolver doesn't go looking through the real Homebrew.
    mkdir("{$this->dir}/brew/bin", 0755, true);
    file_put_contents("{$this->dir}/brew/bin/brew", "#!/bin/sh\n");
    chmod("{$this->dir}/brew/bin/brew", 0755);
    $settings->set('homebrew.prefix', "{$this->dir}/brew");
    $inherited = ['SHELL' => '/bin/zsh', 'HOME' => $this->dir, 'PATH' => '/usr/bin'];
    $login = (new FakeShell)->expect(['/bin/zsh', '+m', '-lic', 'printf __DOTSNITCH_ENV__; /usr/bin/env -0'], exitCode: 1);
    $repo = new DotfilesRepo($settings, $inherited);
    $repo->resolve($this->root);
    $this->state = new RepoState($repo, new ProcessShell, new PathResolver($login, $settings, $inherited));
});

afterEach(function () {
    putenv('GIT_CONFIG_GLOBAL');
    putenv('GIT_CONFIG_SYSTEM');
    Prompt::interactive(true);
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * Runs git in $repo as a throwaway author.
 */
function gitIn(string $repo, string ...$arguments): void
{
    $command = ['/usr/bin/git', '-C', $repo, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', ...$arguments];
    exec(implode(' ', array_map(escapeshellarg(...), $command)).' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException('git '.implode(' ', $arguments).' failed: '.implode("\n", $output));
    }
}

function commitFile(string $repo, string $name): void
{
    file_put_contents("{$repo}/{$name}", bin2hex(random_bytes(4))."\n");
    gitIn($repo, 'add', $name);
    gitIn($repo, 'commit', '-q', '-m', "Add {$name}");
}

/**
 * Scans, keeps the log lines on the test, and returns "kind subject (detail)" per item.
 *
 * @return list<string>
 */
function repoDrift(): array
{
    $log = [];
    $report = test()->state->scan([], function (string $line) use (&$log) {
        $log[] = $line;
    });
    test()->log = $log;
    test()->items = $report->items;

    return array_map(fn (DriftItem $item) => "{$item->kind->value} {$item->subject->key()} ({$item->detail})", $report->items);
}

/**
 * Pushes a commit to the remote from a second clone, the way another Mac would.
 */
function pushFromAnotherMac(): void
{
    $other = test()->dir.'/other';
    exec('/usr/bin/git clone -q '.escapeshellarg(test()->remote).' '.escapeshellarg($other));
    commitFile($other, 'npm.txt');
    gitIn($other, 'push', '-q');
}

it('finds nothing in a clean repo that matches its remote', function () {
    expect(repoDrift())->toBe([])->and($this->log)->toBe([]);
});

it('reports changes that are not committed and commits that are not pushed', function () {
    commitFile($this->root, 'cargo.txt');
    commitFile($this->root, 'pipx.txt');
    file_put_contents("{$this->root}/Brewfile", "brew \"jq\"\n");
    touch("{$this->root}/new.txt");

    expect(repoDrift())->toBe(['uncommitted worktree:main (2 files)', 'unpushed local:main (2 commits)']);
});

it('reports a remote that has moved on, without fetching', function () {
    pushFromAnotherMac();

    expect(repoDrift())->toBe(["behind remote:main (origin/main has commits this Mac doesn't)"])
        ->and(file_exists("{$this->root}/npm.txt"))->toBeFalse();
});

it('pulls and pushes, and then finds nothing', function () {
    pushFromAnotherMac();
    repoDrift();
    [$pull] = $this->state->steps(new Decision($this->items[0], Action::Pull));

    expect($pull->description)->toBe("/usr/bin/git -C {$this->root} pull --ff-only")
        ->and(($pull->run)())->toBe(['kind' => 'command', 'command' => ['/usr/bin/git', '-C', $this->root, 'pull', '--ff-only']])
        ->and(repoDrift())->toBe([]);

    gitIn($this->root, 'config', 'user.name', 'Test');
    gitIn($this->root, 'config', 'user.email', 'test@example.com');
    commitFile($this->root, 'cargo.txt');
    repoDrift();
    [$push] = $this->state->steps(new Decision($this->items[0], Action::Push));
    ($push->run)();

    expect(repoDrift())->toBe([]);
});

it('throws git\'s error when a pull can\'t fast-forward', function () {
    pushFromAnotherMac();
    commitFile($this->root, 'cargo.txt');
    repoDrift();
    [$pull] = $this->state->steps(new Decision($this->items[1], Action::Pull));

    expect(fn () => ($pull->run)())->toThrow(RuntimeException::class);
});

it('offers a push or a pull, and leaves committing to you', function () {
    pushFromAnotherMac();
    commitFile($this->root, 'cargo.txt');
    touch("{$this->root}/new.txt");
    repoDrift();

    expect(array_map(fn (DriftItem $item) => array_keys($this->state->actionsFor($item)), $this->items))->toBe([
        ['skip'],
        ['push', 'ignore', 'skip'],
        ['pull', 'ignore', 'skip'],
    ]);
});

it('carries on when the remote can\'t be reached', function () {
    touch("{$this->root}/new.txt");
    gitIn($this->root, 'remote', 'set-url', 'origin', "{$this->dir}/gone.git");

    expect(repoDrift())->toBe(['uncommitted worktree:main (1 file)'])
        ->and($this->log)->toBe(["Couldn't read refs/heads/main from origin, so pulls weren't checked"]);
});

it('checks only for changes on a branch with no upstream', function () {
    gitIn($this->root, 'switch', '-q', '-c', 'try');
    commitFile($this->root, 'cargo.txt');

    expect(repoDrift())->toBe([])
        ->and($this->log)->toBe(["try has no upstream, so pushes and pulls weren't checked"]);
});

it('says so when the dotfiles are not a git repository', function () {
    exec('rm -rf '.escapeshellarg("{$this->root}/.git"));

    expect(repoDrift())->toBe([])->and($this->log)->toBe(["{$this->root} isn't a git repository"]);
});
