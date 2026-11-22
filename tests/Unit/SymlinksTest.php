<?php

declare(strict_types=1);

use App\Data\Link;
use App\Support\DotfilesRepo;
use App\Support\ManifestEditor;
use App\Support\UserSettings;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\Step;
use App\Targets\Symlinks;
use Laravel\Prompts\Prompt;
use Tests\Fakes\FakeShell;

beforeEach(function () {
    $dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    mkdir("{$dir}/home/dotfiles", recursive: true);
    $this->dir = realpath($dir);
    $this->home = "{$this->dir}/home";
    $this->root = "{$this->home}/dotfiles";
    $this->shell = new FakeShell;
    $this->editor = new ManifestEditor("{$this->dir}/backups");

    Prompt::interactive(false);
    $repo = new DotfilesRepo(new UserSettings("{$this->dir}/config.json"), ['HOME' => $this->home]);
    $repo->resolve($this->root);
    $this->symlinks = new Symlinks($repo, $this->shell, $this->editor, ['HOME' => $this->home]);
});

afterEach(function () {
    Prompt::interactive(true);
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * Writes $contents to $path, creating the folders above it.
 */
function putFile(string $path, string $contents = ''): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), recursive: true);
    }

    file_put_contents($path, $contents);
}

/**
 * Scans and returns [kind, target, source, detail] per item, so a test can compare all of it at
 * once. Log lines land in $this->log.
 *
 * @param  list<string>  $manifests  repo-relative
 * @return list<array{string, string, string, ?string}>
 */
function scanLinks(array $manifests): array
{
    $test = test();
    $log = [];
    $report = $test->symlinks->scan(
        array_map(fn (string $file) => "{$test->root}/{$file}", $manifests),
        function (string $line) use (&$log) {
            $log[] = $line;
        },
    );
    $test->log = $log;

    return array_map(
        fn (DriftItem $item) => [$item->kind->value, $item->subject->target, $item->subject->source, $item->detail],
        $report->items,
    );
}

/**
 * Runs every step for one decision, the way apply() does.
 *
 * @return list<array<string, mixed>> what each step returned
 */
function runLinkAction(Action $action, DriftItem $item): array
{
    return array_map(fn (Step $step) => ($step->run)(), test()->symlinks->steps(new Decision($item, $action)));
}

it('reports every kind of drift from a Dotbot config and existing links', function () {
    putFile("{$this->root}/install.conf.yaml", <<<'YAML'
        - defaults:
            link:
              relink: true
        - link:
            ~/.zshrc:
            ~/.gitconfig: .gitconfig
            ~/.vimrc:
              path: .vimrc
            $HOME/.tmux.conf: .tmux.conf
            ~/.gone:
              path: gone
              if: '[ -f /nowhere ]'
        YAML);

    foreach (['zshrc', '.gitconfig', '.vimrc', '.tmux.conf', 'gone'] as $file) {
        putFile("{$this->root}/{$file}");
    }

    putFile("{$this->home}/.gitconfig", "[user]\n");
    putFile("{$this->dir}/old/.vimrc");
    symlink("{$this->dir}/old/.vimrc", "{$this->home}/.vimrc");
    symlink("{$this->root}/.tmux.conf", "{$this->home}/.tmux.conf");
    symlink("{$this->root}/.oldrc", "{$this->home}/.oldrc");

    expect(scanLinks(['install.conf.yaml']))->toBe([
        ['not-linked', "{$this->home}/.zshrc", "{$this->root}/zshrc", 'should point at zshrc'],
        ['conflict', "{$this->home}/.gitconfig", "{$this->root}/.gitconfig", 'a real file is in the way'],
        ['wrong-target', "{$this->home}/.vimrc", "{$this->root}/.vimrc", "points at {$this->dir}/old/.vimrc"],
        ['broken', "{$this->home}/.oldrc", "{$this->root}/.oldrc", '.oldrc is gone from the repo'],
    ])->and($this->log)->toContain('Skipped ~/.gone in install.conf.yaml, it has an if: condition');
});

it('links a Dotbot glob into the target folder with its prefix', function () {
    putFile("{$this->root}/install.conf.yaml", <<<'YAML'
        - link:
            ~/bin:
              glob: true
              path: scripts/*
              prefix: my-
        YAML);
    putFile("{$this->root}/scripts/backup");
    putFile("{$this->root}/scripts/sync");

    expect(array_column(scanLinks(['install.conf.yaml']), 1))->toBe(["{$this->home}/bin/my-backup", "{$this->home}/bin/my-sync"]);
});

it('maps Stow packages with --dotfiles and skips what Stow skips', function () {
    putFile("{$this->root}/.stowrc", "--target=~\n--dotfiles\n");
    putFile("{$this->root}/zsh/dot-zshrc");
    putFile("{$this->root}/zsh/README.md");
    putFile("{$this->root}/nvim/dot-config/nvim/init.lua");
    putFile("{$this->root}/docs/setup.md");

    expect(array_column(scanLinks(['.stowrc']), 1, 2))->toBe([
        "{$this->root}/nvim/dot-config/nvim/init.lua" => "{$this->home}/.config/nvim/init.lua",
        "{$this->root}/zsh/dot-zshrc" => "{$this->home}/.zshrc",
    ]);
});

it("doesn't read Stow packages in a Dotbot repo without a .stowrc", function () {
    putFile("{$this->root}/install.conf.yaml", "- link:\n    ~/.zshrc: zsh/zshrc\n");
    putFile("{$this->root}/zsh/zshrc");
    putFile("{$this->root}/dotbot/.editorconfig");
    putFile("{$this->root}/dotbot/bin/dotbot");

    expect(array_column(scanLinks(['install.conf.yaml']), 1))->toBe(["{$this->home}/.zshrc"]);
});

it('skips a submodule that looks like a Stow package', function () {
    putFile("{$this->root}/.stowrc", "--target=~\n");
    putFile("{$this->root}/zsh/.zshrc");
    putFile("{$this->root}/vendored/.git", "gitdir: ../.git/modules/vendored\n");
    putFile("{$this->root}/vendored/.editorconfig");

    expect(array_column(scanLinks(['.stowrc']), 1))->toBe(["{$this->home}/.zshrc"]);
});

it('keeps the first layout when two claim the same path, and says so', function () {
    putFile("{$this->root}/install.conf.yaml", "- link:\n    ~/.zshrc: zsh/zshrc\n");
    putFile("{$this->root}/zsh/zshrc");
    putFile("{$this->root}/zsh/zshrc.symlink");

    expect(array_column(scanLinks(['install.conf.yaml', 'zsh/zshrc.symlink']), 2))->toBe(["{$this->root}/zsh/zshrc"])
        ->and($this->log)->toContain("{$this->home}/.zshrc: kept {$this->root}/zsh/zshrc, not {$this->root}/zsh/zshrc.symlink");
});

it('reads folders the repo mirrors past the usual depth, and nothing else', function () {
    putFile("{$this->root}/.vim/pack/mine/plugin.vim");
    putFile("{$this->dir}/old/.vim/pack/mine/plugin.vim");
    mkdir("{$this->home}/.vim/pack", recursive: true);
    symlink("{$this->dir}/old/.vim/pack/mine", "{$this->home}/.vim/pack/mine");
    mkdir("{$this->home}/.cache/deep", recursive: true);
    symlink("{$this->root}/.vim", "{$this->home}/.cache/deep/vim");

    expect(scanLinks([]))->toBe([
        ['wrong-target', "{$this->home}/.vim/pack/mine", "{$this->root}/.vim/pack/mine", "points at {$this->dir}/old/.vim/pack/mine"],
    ]);
});

it('reports the folder link when a linked folder points somewhere else', function () {
    putFile("{$this->root}/install.conf.yaml", "- link:\n    ~/.config/nvim/init.lua: .config/nvim/init.lua\n");
    putFile("{$this->root}/.config/nvim/init.lua");
    putFile("{$this->dir}/old/nvim/init.lua");
    mkdir("{$this->home}/.config");
    symlink("{$this->dir}/old/nvim", "{$this->home}/.config/nvim");

    expect(scanLinks(['install.conf.yaml']))->toBe([
        ['wrong-target', "{$this->home}/.config/nvim", "{$this->root}/.config/nvim", "points at {$this->dir}/old/nvim"],
    ]);
});

it('offers Show diff and Adopt for a conflict, with Ignore and Skip last', function () {
    $item = new DriftItem('symlinks', DriftKind::Conflict, new Link("{$this->root}/.gitconfig", "{$this->home}/.gitconfig"));

    expect($this->symlinks->actionsFor($item))->toBe([
        'backup-link' => 'Back up and link',
        'diff' => 'Show diff',
        'adopt' => 'Adopt into dotfiles',
        'ignore' => 'Ignore on this Mac',
        'skip' => 'Skip',
    ]);
});

it('creates the link and the folders above it', function () {
    putFile("{$this->root}/.config/nvim/init.lua");
    $link = new Link("{$this->root}/.config/nvim/init.lua", "{$this->home}/.config/nvim/init.lua");

    runLinkAction(Action::Link, new DriftItem('symlinks', DriftKind::NotLinked, $link));

    expect(readlink($link->target))->toBe($link->source);
});

it("says which link it couldn't create, even when warnings throw", function () {
    putFile("{$this->root}/.vimrc");
    mkdir("{$this->home}/locked", 0500);
    $link = new Link("{$this->root}/.vimrc", "{$this->home}/locked/.vimrc");
    throwWarnings();

    try {
        expect(fn () => runLinkAction(Action::Link, new DriftItem('symlinks', DriftKind::NotLinked, $link)))
            ->toThrow(RuntimeException::class, "Could not link {$link->target} to {$link->source}");
    } finally {
        restore_error_handler();
    }
});

it('words the group question so it reads after "pick the ones to"', function () {
    expect(Action::Link->verb())->toBe('link')
        ->and(Action::Repoint->verb())->toBe('repoint')
        ->and(Action::Unlink->verb())->toBe('unlink')
        ->and(Action::AddToManifest->verb())->toBe('add to dotfiles');
});

it('moves the file in the way into backups before linking', function () {
    putFile("{$this->root}/.gitconfig", "[repo]\n");
    putFile("{$this->home}/.gitconfig", "[home]\n");
    $item = new DriftItem('symlinks', DriftKind::Conflict, new Link("{$this->root}/.gitconfig", "{$this->home}/.gitconfig"));

    expect(array_column($this->symlinks->steps(new Decision($item, Action::BackUpAndLink)), 'description'))->toBe([
        "mv {$this->home}/.gitconfig {$this->dir}/backups{$this->home}/.gitconfig",
        "ln -s {$this->root}/.gitconfig {$this->home}/.gitconfig",
    ]);

    $effects = runLinkAction(Action::BackUpAndLink, $item);

    expect(readlink("{$this->home}/.gitconfig"))->toBe("{$this->root}/.gitconfig")
        ->and(file_get_contents("{$this->dir}/backups{$this->home}/.gitconfig"))->toBe("[home]\n")
        ->and($effects)->toBe([
            ['kind' => 'move', 'from' => "{$this->home}/.gitconfig", 'to' => "{$this->dir}/backups{$this->home}/.gitconfig"],
            ['kind' => 'link', 'target' => "{$this->home}/.gitconfig", 'source' => "{$this->root}/.gitconfig"],
        ]);
});

it("adopts the home copy into the repo and backs up the repo's", function () {
    putFile("{$this->root}/.gitconfig", "[repo]\n");
    putFile("{$this->home}/.gitconfig", "[home]\n");

    $effects = runLinkAction(Action::Adopt, new DriftItem('symlinks', DriftKind::Conflict, new Link("{$this->root}/.gitconfig", "{$this->home}/.gitconfig")));

    expect(readlink("{$this->home}/.gitconfig"))->toBe("{$this->root}/.gitconfig")
        ->and(file_get_contents("{$this->root}/.gitconfig"))->toBe("[home]\n")
        ->and(file_get_contents("{$this->dir}/backups{$this->root}/.gitconfig"))->toBe("[repo]\n")
        ->and($effects)->toBe([
            ['kind' => 'move', 'from' => "{$this->root}/.gitconfig", 'to' => "{$this->dir}/backups{$this->root}/.gitconfig"],
            ['kind' => 'move', 'from' => "{$this->home}/.gitconfig", 'to' => "{$this->root}/.gitconfig"],
            ['kind' => 'link', 'target' => "{$this->home}/.gitconfig", 'source' => "{$this->root}/.gitconfig"],
        ]);
});

it('repoints a link and leaves the old destination alone', function () {
    putFile("{$this->root}/.vimrc");
    putFile("{$this->dir}/old/.vimrc", 'old');
    symlink("{$this->dir}/old/.vimrc", "{$this->home}/.vimrc");

    $effects = runLinkAction(Action::Repoint, new DriftItem('symlinks', DriftKind::WrongTarget, new Link("{$this->root}/.vimrc", "{$this->home}/.vimrc")));

    expect(readlink("{$this->home}/.vimrc"))->toBe("{$this->root}/.vimrc")
        ->and(file_get_contents("{$this->dir}/old/.vimrc"))->toBe('old')
        ->and($effects)->toBe([
            ['kind' => 'repoint', 'target' => "{$this->home}/.vimrc", 'source' => "{$this->root}/.vimrc", 'pointed_to' => "{$this->dir}/old/.vimrc"],
        ]);
});

it('removes a broken link', function () {
    symlink("{$this->root}/.oldrc", "{$this->home}/.oldrc");

    $effects = runLinkAction(Action::Unlink, new DriftItem('symlinks', DriftKind::Broken, new Link("{$this->root}/.oldrc", "{$this->home}/.oldrc")));

    expect(is_link("{$this->home}/.oldrc"))->toBeFalse()
        ->and($effects)->toBe([['kind' => 'unlink', 'target' => "{$this->home}/.oldrc", 'pointed_to' => "{$this->root}/.oldrc"]]);
});

it('refuses to remove a real file', function () {
    putFile("{$this->home}/.oldrc", 'keep me');

    runLinkAction(Action::Unlink, new DriftItem('symlinks', DriftKind::Broken, new Link("{$this->root}/.oldrc", "{$this->home}/.oldrc")));
})->throws(RuntimeException::class, "isn't a symlink anymore");

it("shows diff's output, or says there's no difference", function () {
    $link = new Link("{$this->root}/.gitconfig", "{$this->home}/.gitconfig");
    $item = new DriftItem('symlinks', DriftKind::Conflict, $link);
    $command = ['/usr/bin/diff', '-ru', $link->target, $link->source];

    $this->shell->expect($command, "-[home]\n+[repo]\n", exitCode: 1);
    $differs = $this->symlinks->diff($item);
    $this->shell->expect($command);

    expect($differs)->toBe("-[home]\n+[repo]")
        ->and($this->symlinks->diff($item))->toBe('No differences.');
});
