<?php

declare(strict_types=1);

use App\Data\Package;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use App\Support\UserSettings;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\DriftReport;
use App\Targets\Cargo;
use App\Targets\Composer;
use App\Targets\Gh;
use App\Targets\Mas;
use App\Targets\Npm;
use App\Targets\Pipx;
use App\Targets\Pnpm;
use App\Targets\Uv;
use App\Targets\Vscode;
use Tests\Fakes\FakeShell;

/**
 * A temp home with fake composer, npm, pnpm, mas, gh, pipx, uv, cargo and code on PATH. npm is a symlink into
 * lib/node_modules/npm the way a node install lays it out. The dir goes when the object does.
 */
final class GlobalsMac
{
    public readonly string $dir;

    public readonly FakeShell $shell;

    public readonly PathResolver $paths;

    public readonly ManifestEditor $editor;

    /**
     * Expects the lookups PathResolver makes, so a test only sets up its own commands.
     */
    public function __construct()
    {
        $this->dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
        mkdir("{$this->dir}/bin", 0755, true);
        mkdir("{$this->dir}/repo");
        mkdir("{$this->dir}/lib/node_modules/npm/bin", 0755, true);

        foreach (['bin/composer', 'bin/pnpm', 'bin/mas', 'bin/gh', 'bin/pipx', 'bin/uv', 'bin/cargo', 'bin/code', 'lib/node_modules/npm/bin/npm-cli.js'] as $file) {
            file_put_contents("{$this->dir}/{$file}", "#!/bin/sh\n");
            chmod("{$this->dir}/{$file}", 0755);
        }
        symlink('../lib/node_modules/npm/bin/npm-cli.js', "{$this->dir}/bin/npm");

        // The login shell fails, so the inherited PATH below is the login PATH too.
        $this->shell = (new FakeShell)
            ->expect(['/bin/zsh', '+m', '-lic', 'printf __DOTSNITCH_ENV__; /usr/bin/env -0'], exitCode: 1)
            ->expect([$this->bin('composer'), 'global', 'config', 'bin-dir', '--absolute'], exitCode: 1)
            ->expect([$this->bin('npm'), 'prefix', '-g'], exitCode: 1)
            ->expect([$this->bin('pnpm'), 'bin', '-g'], "{$this->dir}/bin\n")
            ->expect([$this->bin('uv'), 'tool', 'dir', '--bin'], exitCode: 1);
        $this->paths = new PathResolver(
            $this->shell,
            new UserSettings("{$this->dir}/config.json"),
            ['SHELL' => '/bin/zsh', 'HOME' => $this->dir, 'PATH' => "{$this->dir}/bin"],
            [],
        );
        $this->editor = new ManifestEditor("{$this->dir}/backups");
    }

    /**
     * Removes the temp home.
     */
    public function __destruct()
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /**
     * @return string the path of a fake binary, such as bin/pnpm
     */
    public function bin(string $name): string
    {
        return "{$this->dir}/bin/{$name}";
    }

    /**
     * @return string the manifest's path, under repo/
     */
    public function manifest(string $name, string $contents): string
    {
        $path = "{$this->dir}/repo/{$name}";
        is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param  string  $fixture  relative to tests/Fixtures
     */
    public function fixture(string $fixture): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/'.$fixture);
    }
}

/**
 * The item for a key like "missing:package:typescript", in the report's check.
 */
function globalItem(DriftReport $report, string $kindAndPackage): DriftItem
{
    $key = "{$report->check}:{$kindAndPackage}";

    return collect($report->items)->first(fn (DriftItem $item) => $item->key() === $key)
        ?? throw new LogicException("No drift item {$key}");
}

/**
 * @return list<array{string, string, ?string}> kind, package key and detail
 */
function globalDrift(DriftReport $report): array
{
    return array_map(fn (DriftItem $item) => [$item->kind->value, $item->subject->key(), $item->detail], $report->items);
}

/**
 * A Composer check that sees show-direct.json as installed.
 */
function composerOn(GlobalsMac $mac): Composer
{
    $mac->shell->expect([$mac->bin('composer'), 'global', 'show', '--direct', '--format=json'], $mac->fixture('composer/show-direct.json'));

    return new Composer($mac->shell, $mac->paths, $mac->editor);
}

/**
 * An npm check that sees ls-global.json as installed.
 */
function npmOn(GlobalsMac $mac): Npm
{
    $mac->shell->expect([$mac->bin('npm'), 'ls', '-g', '--depth=0', '--json'], $mac->fixture('npm/ls-global.json'));

    return new Npm($mac->shell, $mac->paths, $mac->editor);
}

/**
 * A pnpm check that sees list-global.json as installed.
 */
function pnpmOn(GlobalsMac $mac): Pnpm
{
    $mac->shell->expect([$mac->bin('pnpm'), 'list', '-g', '--json'], $mac->fixture('pnpm/list-global.json'));

    return new Pnpm($mac->shell, $mac->paths, $mac->editor);
}

it('checks a Composerfile against composer global show', function () {
    $mac = new GlobalsMac;
    $manifest = $mac->manifest('Composerfile', "laravel/installer:^5\npsr/log\n");

    $report = composerOn($mac)->scan([$manifest], fn () => null);

    expect(globalDrift($report))->toBe([
        ['missing', 'package:laravel/installer', null],
        ['untracked', 'package:fzaninotto/faker', null],
    ]);
});

it('says which manifests list a package and which version is installed', function () {
    $mac = new GlobalsMac;
    $base = $mac->manifest('Composerfile', "laravel/installer:^5\npsr/log\n");
    $overlay = $mac->manifest('Composerfile.mac', "laravel/installer\n");
    $composer = composerOn($mac);

    $report = $composer->scan([$base, $overlay], fn () => null);

    expect($composer->sides(globalItem($report, 'missing:package:laravel/installer')))
        ->toBe(['dotfiles' => 'listed in Composerfile, Composerfile.mac', 'mac' => 'not installed'])
        ->and($composer->sides(globalItem($report, 'untracked:package:fzaninotto/faker')))
        ->toBe(['dotfiles' => 'not listed', 'mac' => 'installed v1.5.0']);
});

it('names the folder of each manifest when two share a file name', function () {
    $mac = new GlobalsMac;
    $home = $mac->manifest('home/Composerfile', "laravel/installer\n");
    $work = $mac->manifest('work/Composerfile', "laravel/installer\n");
    $composer = composerOn($mac);

    $report = $composer->scan([$home, $work], fn () => null);

    expect($composer->sides(globalItem($report, 'missing:package:laravel/installer'))['dotfiles'])
        ->toBe('listed in home/Composerfile, work/Composerfile');
});

it('reads composer.json require without platform packages, whatever the case', function () {
    $mac = new GlobalsMac;
    $manifest = $mac->manifest('composer/composer.json', '{"require": {"php": ">=8.2", "ext-mbstring": "*", "PSR/Log": "^3.0", "fzaninotto/faker": "^1.5"}}');

    $report = composerOn($mac)->scan([$manifest], fn () => null);

    expect($report->items)->toBe([]);
});

it('reads a composer.json overlay named for a hostname with dots in it', function () {
    $mac = new GlobalsMac;
    $manifest = $mac->manifest('composer.json.kennys-mbp.local', '{"require": {"psr/log": "^3.0", "fzaninotto/faker": "^1.5"}}');

    $report = composerOn($mac)->scan([$manifest], fn () => null);

    expect($report->items)->toBe([]);
});

it('refuses composer show output listing a package with no name', function () {
    $mac = new GlobalsMac;
    $composer = composerOn($mac);
    $mac->shell->expect([$mac->bin('composer'), 'global', 'show', '--direct', '--format=json'], '{"installed": [{"version": "1.0.0"}]}');

    expect(fn () => $composer->scan([$mac->manifest('Composerfile', "psr/log\n")], fn () => null))
        ->toThrow(RuntimeException::class, 'composer global show listed a package with no name');
});

it('takes the [] Composer prints when nothing is installed', function () {
    $mac = new GlobalsMac;
    $composer = composerOn($mac);
    $mac->shell->expect([$mac->bin('composer'), 'global', 'show', '--direct', '--format=json'], "[]\n");

    $report = $composer->scan([$mac->manifest('Composerfile', "psr/log\n")], fn () => null);

    expect(globalDrift($report))->toBe([['missing', 'package:psr/log', null]]);
});

it('installs with the constraint and adds with a caret on the installed version', function () {
    $mac = new GlobalsMac;
    $composer = composerOn($mac);
    $globals = $mac->manifest('composer.json', "{\n  \"require\": {\n    \"psr/log\": \"^3.0\"\n  }\n}\n");
    $report = $composer->scan([$mac->manifest('Composerfile', "laravel/installer:^5\npsr/log\n")], fn () => null);
    $mac->shell->expect([$mac->bin('composer'), 'global', 'require', 'laravel/installer:^5']);

    [$install] = $composer->steps(new Decision(globalItem($report, 'missing:package:laravel/installer'), Action::Install));
    [$add] = $composer->steps(new Decision(globalItem($report, 'untracked:package:fzaninotto/faker'), Action::AddToManifest, [$globals]));
    ($install->run)();
    ($add->run)();

    expect($install->description)->toBe($mac->bin('composer').' global require '.escapeshellarg('laravel/installer:^5'))
        ->and(collect($mac->shell->ran())->last())->toBe([$mac->bin('composer'), 'global', 'require', 'laravel/installer:^5'])
        ->and(file_get_contents($globals))->toBe("{\n  \"require\": {\n    \"psr/log\": \"^3.0\",\n    \"fzaninotto/faker\": \"^1.5.0\"\n  }\n}\n");
});

it('never reports the npm running the check as untracked, and installs with the pin', function () {
    $mac = new GlobalsMac;
    $npm = npmOn($mac);

    $report = $npm->scan([$mac->manifest('npm-global.txt', "typescript@6\n")], fn () => null);
    [$install] = $npm->steps(new Decision(globalItem($report, 'missing:package:typescript'), Action::Install));

    expect(globalDrift($report))->toBe([
        ['missing', 'package:typescript', null],
        ['untracked', 'package:neovim', null],
    ])
        ->and($install->description)->toBe($mac->bin('npm').' i -g typescript@6');
});

it('reads and removes npm package.json dependencies', function () {
    $mac = new GlobalsMac;
    $npm = npmOn($mac);
    $manifest = $mac->manifest('npm/package.json', "{\n  \"dependencies\": {\n    \"neovim\": \"^5.5.0\",\n    \"prettier\": \"^3\"\n  }\n}\n");
    $report = $npm->scan([$manifest], fn () => null);

    [$remove] = $npm->steps(new Decision(globalItem($report, 'missing:package:prettier'), Action::RemoveFromManifest, [$manifest]));
    ($remove->run)();

    expect(globalDrift($report))->toBe([['missing', 'package:prettier', null]])
        ->and(file_get_contents($manifest))->toBe("{\n  \"dependencies\": {\n    \"neovim\": \"^5.5.0\"\n  }\n}\n");
});

/** A setup/pnpm.sh with a comment, a quoted entry and a pin. */
const PNPM_SETUP = <<<'SH'
pkgs=(
  # Language servers
  intelephense
  "typescript@6" # stay on 6
  prettier
)
pnpm add -g "${pkgs[@]}"

SH;

it('reads pnpm.sh and says when an untracked package shares an install group', function () {
    $mac = new GlobalsMac;

    $report = pnpmOn($mac)->scan([$mac->manifest('pnpm.sh', PNPM_SETUP)], fn () => null);

    expect(globalDrift($report))->toBe([
        ['missing', 'package:prettier', null],
        ['untracked', 'package:typescript-language-server', 'shares a pnpm install group with typescript'],
    ]);
});

it('hints at pnpm setup only when the bin dir is not on your login PATH', function (?string $bin, int $exitCode, ?string $hint) {
    $mac = new GlobalsMac;
    $pnpm = pnpmOn($mac);
    $mac->shell->expect([$mac->bin('pnpm'), 'bin', '-g'], $bin === null ? '' : "{$mac->dir}/{$bin}\n", $exitCode);
    $lines = [];

    $pnpm->scan([$mac->manifest('pnpm.sh', PNPM_SETUP)], function (string $line) use (&$lines) {
        $lines[] = $line;
    });
    $hints = array_values(array_filter($lines, fn (string $line) => str_contains($line, 'pnpm setup')));

    expect($hints)->toBe($hint === null ? [] : [str_replace('{dir}', $mac->dir, $hint)]);
})->with([
    'on PATH' => ['bin', 0, null],
    'off PATH' => ['pnpm-bin', 0, "pnpm's bin dir {dir}/pnpm-bin isn't on your PATH. Run `pnpm setup`, then open a new terminal."],
    'never set up' => [null, 1, "pnpm doesn't know its global bin dir. Run `pnpm setup`, then open a new terminal."],
]);

it('installs one pinned package per pnpm command and removes only its line', function () {
    $mac = new GlobalsMac;
    $pnpm = pnpmOn($mac);
    $manifest = $mac->manifest('pnpm.sh', PNPM_SETUP);
    $item = new DriftItem('pnpm', DriftKind::Missing, new Package('typescript', 'package', '6'));
    $pnpm->scan([$manifest], fn () => null);

    [$install] = $pnpm->steps(new Decision($item, Action::Install));
    [$remove] = $pnpm->steps(new Decision($item, Action::RemoveFromManifest, [$manifest]));
    ($remove->run)();

    expect($install->description)->toBe($mac->bin('pnpm').' add -g typescript@6')
        ->and(file_get_contents($manifest))->toBe(str_replace("  \"typescript@6\" # stay on 6\n", '', PNPM_SETUP));
});

/** Shaped like a dotfiles setup/mas.sh. */
const MAS_SETUP = <<<'SH'
#!/usr/bin/env bash
apps=(
  "497799835:Xcode"
  "1355679052:Dropover"
  "1436522307:Transmit 5"
)

for app in "${apps[@]}"; do
  mas install "${app%%:*}"
done

SH;

/**
 * A mas check that sees list.txt, captured from mas 7, as installed.
 */
function masOn(GlobalsMac $mac): Mas
{
    $mac->shell->expect([$mac->bin('mas'), 'list'], $mac->fixture('mas/list.txt'));

    return new Mas($mac->shell, $mac->paths, $mac->editor);
}

it('names each App Store app, from mas.sh when it is missing and from mas list when untracked', function () {
    $mac = new GlobalsMac;

    $report = masOn($mac)->scan([$mac->manifest('mas.sh', MAS_SETUP)], fn () => null);

    expect(globalDrift($report))->toBe([
        ['missing', 'app:497799835', 'Xcode'],
        ['missing', 'app:1436522307', 'Transmit 5'],
        ['untracked', 'app:1569813296', '1Password for Safari'],
        ['untracked', 'app:937984704', 'Amphetamine'],
        ['untracked', 'app:409222199', 'Cyberduck'],
        ['untracked', 'app:1592917505', 'Noir'],
        ['untracked', 'app:1549370672', 'Save to Raindrop.io'],
        ['untracked', 'app:1596706466', 'Speediness'],
    ]);
});

it('adds an app as id:Name, quoted when the name has a space, and uninstalls with sudo', function () {
    $mac = new GlobalsMac;
    $mas = masOn($mac);
    $manifest = $mac->manifest('mas.sh', MAS_SETUP);
    $report = $mas->scan([$manifest], fn () => null);

    [$add] = $mas->steps(new Decision(globalItem($report, 'untracked:app:1569813296'), Action::AddToManifest, [$manifest]));
    [$remove] = $mas->steps(new Decision(globalItem($report, 'missing:app:1436522307'), Action::RemoveFromManifest, [$manifest]));
    [$uninstall] = $mas->steps(new Decision(globalItem($report, 'untracked:app:937984704'), Action::Uninstall));
    ($add->run)();
    ($remove->run)();

    expect(file_get_contents($manifest))->toBe(str_replace(
        "  \"1436522307:Transmit 5\"\n",
        "  \"1569813296:1Password for Safari\"\n",
        MAS_SETUP,
    ))->and($uninstall->description)->toBe('/usr/bin/sudo '.$mac->bin('mas').' uninstall 937984704');
});

it('reads gh extensions, local ones included, and installs a pinned one with --pin', function () {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('gh'), 'extension', 'list'], $mac->fixture('gh/extension-list.tsv'));
    $gh = new Gh($mac->shell, $mac->paths, $mac->editor);
    $manifest = $mac->manifest('gh-extensions.sh', "extensions=(\n  Dlvhdr/gh-dash\n  cli/gh-webhook@v0.1.0\n)\nfor ext in \"\${extensions[@]}\"; do gh extension install \"\$ext\"; done\n");

    $report = $gh->scan([$manifest], fn () => null);
    [$install] = $gh->steps(new Decision(globalItem($report, 'missing:extension:cli/gh-webhook'), Action::Install));

    expect(globalDrift($report))->toBe([
        ['missing', 'extension:cli/gh-webhook', null],
        ['untracked', 'extension:seachicken/gh-poi', null],
        ['untracked', 'extension:gh-mine', null],
    ])->and($install->description)->toBe($mac->bin('gh').' extension install cli/gh-webhook --pin v0.1.0')
        ->and(array_keys($gh->actionsFor(new DriftItem('gh', DriftKind::Missing, new Package('mine', 'extension')))))
        ->toBe(['remove', 'ignore', 'skip']);
});

it('matches pipx names however they are spelled, skips injected packages, and removes by venv', function () {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('pipx'), 'list', '--json'], $mac->fixture('pipx/list.json'));
    $pipx = new Pipx($mac->shell, $mac->paths, $mac->editor);

    $report = $pipx->scan([$mac->manifest('pipx.txt', "Black\npoetry\nhttpie[socks]>=3\n")], fn () => null);
    [$install] = $pipx->steps(new Decision(globalItem($report, 'missing:package:httpie'), Action::Install));
    [$uninstall] = $pipx->steps(new Decision(new DriftItem('pipx', DriftKind::Untracked, new Package('poetry', 'package')), Action::Uninstall));

    expect(globalDrift($report))->toBe([['missing', 'package:httpie', null]])
        ->and($install->description)->toBe($mac->bin('pipx')." install 'httpie[socks]>=3'")
        ->and($uninstall->description)->toBe($mac->bin('pipx').' uninstall poetry_2');
});

it('reads uv tool lines, not their entry points, and installs with the specifier', function () {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('uv'), 'tool', 'list'], $mac->fixture('uv/tool-list.txt'));
    $uv = new Uv($mac->shell, $mac->paths, $mac->editor);

    $report = $uv->scan([$mac->manifest('uv-tools.txt', "ruff\nHTTPie\nmypy==1.13\n")], fn () => null);
    [$install] = $uv->steps(new Decision(globalItem($report, 'missing:package:mypy'), Action::Install));

    expect(globalDrift($report))->toBe([['missing', 'package:mypy', null]])
        ->and($install->description)->toBe($mac->bin('uv').' tool install mypy==1.13');
});

it('reports every uv tool missing when uv has none, and fails when uv tool list does', function () {
    $mac = new GlobalsMac;
    $uv = new Uv($mac->shell, $mac->paths, $mac->editor);
    $manifest = $mac->manifest('uv-tools.txt', "ruff\n");

    $mac->shell->expect([$mac->bin('uv'), 'tool', 'list'], errorOutput: "No tools installed\n");
    $report = $uv->scan([$manifest], fn () => null);
    $mac->shell->expect([$mac->bin('uv'), 'tool', 'list'], exitCode: 2, errorOutput: "error: boom\n");

    expect(globalDrift($report))->toBe([['missing', 'package:ruff', null]])
        ->and(fn () => $uv->scan([$manifest], fn () => null))->toThrow(RuntimeException::class, 'uv tool failed: error: boom');
});

it('reads cargo crates from crates.io and git, skips their binaries, and installs name@version', function () {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('cargo'), 'install', '--list'], $mac->fixture('cargo/install-list.txt'));
    $cargo = new Cargo($mac->shell, $mac->paths, $mac->editor);

    $report = $cargo->scan([$mac->manifest('cargo.txt', "ripgrep\nbat@0.24\n")], fn () => null);
    [$install] = $cargo->steps(new Decision(globalItem($report, 'missing:crate:bat'), Action::Install));

    expect(globalDrift($report))->toBe([
        ['missing', 'crate:bat', null],
        ['untracked', 'crate:cargo-edit', null],
        ['untracked', 'crate:tool', null],
    ])->and($install->description)->toBe($mac->bin('cargo').' install bat@0.24');
});

it('flags an installed version the pin leaves out, and installs the pin over it', function () {
    $mac = new GlobalsMac;
    $npm = npmOn($mac);

    $report = $npm->scan([$mac->manifest('npm-global.txt', "neovim@4\n")], fn () => null);
    $item = globalItem($report, 'version:package:neovim');
    [$install] = $npm->steps(new Decision($item, Action::Install));

    expect(globalDrift($report))->toBe([['version', 'package:neovim', 'pinned to 4']])
        ->and($npm->actionsFor($item))->toBe(['install' => 'Install 4', 'ignore' => 'Ignore on this Mac', 'skip' => 'Skip'])
        ->and($npm->sides($item))->toBe(['dotfiles' => 'listed in npm-global.txt', 'mac' => 'installed 5.5.0'])
        ->and($install->description)->toBe($mac->bin('npm').' i -g neovim@4');
});

it('reads a whole version, a partial one and a caret range as a pin, and passes anything else', function (string $pin, bool $flagged) {
    $mac = new GlobalsMac;

    $report = npmOn($mac)->scan([$mac->manifest('npm-global.txt', "neovim@{$pin}\n")], fn () => null);

    expect($report->items !== [])->toBe($flagged);
})->with([
    ['5', false],
    ['5.5', false],
    ['5.5.0', false],
    ['v5.5.0', false],
    ['5.50', true],
    ['5.4', true],
    ['^5.1', false],
    ['^5.6', true],
    ['^4', true],
    ['^0.5', true],
    ['latest', false],
    ['>=6', false],
]);

it('checks a Composer caret constraint against a version with a v on it', function () {
    $mac = new GlobalsMac;

    $report = composerOn($mac)->scan([$mac->manifest('Composerfile', "fzaninotto/faker:^1.6\npsr/log:^3\n")], fn () => null);

    expect(globalDrift($report))->toBe([['version', 'package:fzaninotto/faker', 'pinned to ^1.6']]);
});

it('checks pipx and uv versions against a specifier, extras and all', function (string $specifier, bool $flagged) {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('uv'), 'tool', 'list'], $mac->fixture('uv/tool-list.txt'));
    $uv = new Uv($mac->shell, $mac->paths, $mac->editor);

    $report = $uv->scan([$mac->manifest('uv-tools.txt', "httpie\nruff{$specifier}\n")], fn () => null);

    expect($report->items !== [])->toBe($flagged);
})->with([
    ['==0.7.2', false],
    ['==0.7.*', false],
    ['==0.6.*', true],
    ['!=0.7.*', true],
    ['>=0.6,<1', false],
    ['>=0.6,<0.7', true],
    ['[extra]>=0.8', true],
    ['~=0.6', false],
]);

it('forces a pipx install over a version off its pin', function () {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('pipx'), 'list', '--json'], $mac->fixture('pipx/list.json'));
    $pipx = new Pipx($mac->shell, $mac->paths, $mac->editor);

    $report = $pipx->scan([$mac->manifest('pipx.txt', "black==24.1.0\npoetry==1.8.4\n")], fn () => null);
    [$install] = $pipx->steps(new Decision(globalItem($report, 'version:package:black'), Action::Install));

    expect(globalDrift($report))->toBe([['version', 'package:black', 'pinned to ==24.1.0']])
        ->and($install->description)->toBe($mac->bin('pipx').' install --force black==24.1.0');
});

it('removes a gh extension before installing the tag it is pinned to', function () {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('gh'), 'extension', 'list'], $mac->fixture('gh/extension-list.tsv'));
    $gh = new Gh($mac->shell, $mac->paths, $mac->editor);

    $report = $gh->scan([$mac->manifest('gh-extensions.txt', "dlvhdr/gh-dash@v4.7.0\nseachicken/gh-poi@v0.14.1\ngh-mine\n")], fn () => null);
    $steps = $gh->steps(new Decision(globalItem($report, 'version:extension:dlvhdr/gh-dash'), Action::Install));

    expect(globalDrift($report))->toBe([['version', 'extension:dlvhdr/gh-dash', 'pinned to v4.7.0']])
        ->and(array_column($steps, 'description'))->toBe([
            $mac->bin('gh').' extension remove dlvhdr/gh-dash',
            $mac->bin('gh').' extension install dlvhdr/gh-dash --pin v4.7.0',
        ]);
});

it('flags a crate on another version than the one cargo.txt pins', function () {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('cargo'), 'install', '--list'], $mac->fixture('cargo/install-list.txt'));
    $cargo = new Cargo($mac->shell, $mac->paths, $mac->editor);

    $report = $cargo->scan([$mac->manifest('cargo.txt', "ripgrep@14.0\ncargo-edit@0.13\ntool\n")], fn () => null);

    expect(globalDrift($report))->toBe([['version', 'crate:ripgrep', 'pinned to 14.0']]);
});

it('reads the mas lines of a Brewfile and adds an app as one', function () {
    $mac = new GlobalsMac;
    $mas = masOn($mac);
    $brewfile = $mac->manifest('Brewfile', "brew \"jq\"\nmas \"Cyberduck\", id: 409222199\nmas 'Xcode', id: 497799835\n");

    $report = $mas->scan([$brewfile], fn () => null);
    [$add] = $mas->steps(new Decision(globalItem($report, 'untracked:app:1355679052'), Action::AddToManifest, [$brewfile]));
    [$remove] = $mas->steps(new Decision(globalItem($report, 'missing:app:497799835'), Action::RemoveFromManifest, [$brewfile]));
    ($add->run)();
    ($remove->run)();

    expect(array_slice(globalDrift($report), 0, 2))->toBe([
        ['missing', 'app:497799835', 'Xcode'],
        ['untracked', 'app:1569813296', '1Password for Safari'],
    ])->and(file_get_contents($brewfile))->toBe("brew \"jq\"\nmas \"Cyberduck\", id: 409222199\nmas \"Dropover\", id: 1355679052\n");
});

it('only takes a Brewfile as a manifest when it has a line for the check', function () {
    $mac = new GlobalsMac;
    $mas = new Mas($mac->shell, $mac->paths, $mac->editor);
    $vscode = new Vscode($mac->shell, $mac->paths, $mac->editor);
    $brewfile = $mac->manifest('Brewfile.work', "brew \"jq\"\nvscode \"editorconfig.editorconfig\"\n");

    expect($mas->hasEntriesIn($brewfile))->toBeFalse()
        ->and($vscode->hasEntriesIn($brewfile))->toBeTrue()
        ->and($mas->hasEntriesIn($mac->manifest('mas.txt', '')))->toBeTrue();
});

it('reads the cargo, uv and npm lines of a Brewfile and edits only its own', function () {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('cargo'), 'install', '--list'], $mac->fixture('cargo/install-list.txt'));
    $mac->shell->expect([$mac->bin('uv'), 'tool', 'list'], $mac->fixture('uv/tool-list.txt'));
    $cargo = new Cargo($mac->shell, $mac->paths, $mac->editor);
    $uv = new Uv($mac->shell, $mac->paths, $mac->editor);
    $npm = npmOn($mac);
    $brewfile = $mac->manifest('Brewfile', "brew \"jq\"\ncargo \"ripgrep\"\nuv \"Ruff\"\nnpm \"typescript\"\n");

    $crates = $cargo->scan([$brewfile], fn () => null);
    $tools = $uv->scan([$brewfile], fn () => null);
    $packages = $npm->scan([$brewfile], fn () => null);
    [$addCrate] = $cargo->steps(new Decision(globalItem($crates, 'untracked:crate:cargo-edit'), Action::AddToManifest, [$brewfile]));
    [$addTool] = $uv->steps(new Decision(globalItem($tools, 'untracked:package:httpie'), Action::AddToManifest, [$brewfile]));
    [$removePackage] = $npm->steps(new Decision(globalItem($packages, 'missing:package:typescript'), Action::RemoveFromManifest, [$brewfile]));
    ($addCrate->run)();
    ($addTool->run)();
    ($removePackage->run)();

    expect(globalDrift($crates))->toBe([['untracked', 'crate:cargo-edit', null], ['untracked', 'crate:tool', null]])
        ->and(globalDrift($tools))->toBe([['untracked', 'package:httpie', null]])
        ->and(globalDrift($packages))->toBe([['missing', 'package:typescript', null], ['untracked', 'package:neovim', null]])
        ->and(file_get_contents($brewfile))->toBe("brew \"jq\"\ncargo \"ripgrep\"\ncargo \"cargo-edit\"\nuv \"Ruff\"\nuv \"httpie\"\n")
        ->and($cargo->hasEntriesIn($mac->manifest('Brewfile.work', "brew \"jq\"\n")))->toBeFalse();
});

it('checks VS Code extensions against a list or a Brewfile, whatever the case of an id', function (string $name, string $contents, string $afterAdd) {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('code'), '--list-extensions', '--show-versions'], "EditorConfig.EditorConfig@0.16.4\nbmewburn.vscode-intelephense-client@1.14.4\n");
    $vscode = new Vscode($mac->shell, $mac->paths, $mac->editor);
    $manifest = $mac->manifest($name, $contents);

    $report = $vscode->scan([$manifest], fn () => null);
    [$install] = $vscode->steps(new Decision(globalItem($report, 'missing:extension:redhat.vscode-yaml'), Action::Install));
    [$uninstall] = $vscode->steps(new Decision(globalItem($report, 'untracked:extension:bmewburn.vscode-intelephense-client'), Action::Uninstall));
    [$add] = $vscode->steps(new Decision(globalItem($report, 'untracked:extension:bmewburn.vscode-intelephense-client'), Action::AddToManifest, [$manifest]));
    ($add->run)();

    expect(globalDrift($report))->toBe([
        ['missing', 'extension:redhat.vscode-yaml', null],
        ['untracked', 'extension:bmewburn.vscode-intelephense-client', null],
    ])->and($install->description)->toBe($mac->bin('code').' --install-extension redhat.vscode-yaml')
        ->and($uninstall->description)->toBe($mac->bin('code').' --uninstall-extension bmewburn.vscode-intelephense-client')
        ->and(file_get_contents($manifest))->toBe($afterAdd);
})->with([
    'a list' => [
        'vscode-extensions.txt',
        "editorconfig.editorconfig\nRedHat.vscode-yaml\n",
        "editorconfig.editorconfig\nRedHat.vscode-yaml\nbmewburn.vscode-intelephense-client\n",
    ],
    'a Brewfile' => [
        'Brewfile',
        "cask \"zed\"\nvscode \"editorconfig.editorconfig\"\nvscode \"RedHat.vscode-yaml\"\n",
        "cask \"zed\"\nvscode \"editorconfig.editorconfig\"\nvscode \"RedHat.vscode-yaml\"\nvscode \"bmewburn.vscode-intelephense-client\"\n",
    ],
]);

it('flags an extension off the version its list pins', function () {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('code'), '--list-extensions', '--show-versions'], "editorconfig.editorconfig@0.16.4\n");
    $vscode = new Vscode($mac->shell, $mac->paths, $mac->editor);

    $report = $vscode->scan([$mac->manifest('vscode-extensions.txt', "editorconfig.editorconfig@0.16.3\n")], fn () => null);
    [$install] = $vscode->steps(new Decision($report->items[0], Action::Install));

    expect(globalDrift($report))->toBe([['version', 'extension:editorconfig.editorconfig', 'pinned to 0.16.3']])
        ->and($install->description)->toBe($mac->bin('code').' --install-extension editorconfig.editorconfig@0.16.3');
});

it('lists outdated npm globals, which npm reports with exit code 1', function () {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('npm'), 'outdated', '-g', '--json'], '{"typescript": {"current": "5.9.2", "wanted": "5.9.3", "latest": "6.0.1", "location": "/lib/node_modules/typescript"}}', exitCode: 1);

    [$entry] = (new Npm($mac->shell, $mac->paths, $mac->editor))->outdated();

    expect([$entry['package']->key(), $entry['package']->version, $entry['latest']])->toBe(['package:typescript', '5.9.2', '6.0.1']);
});

it('lists outdated Composer globals, and takes the [] Composer prints for none', function () {
    $mac = new GlobalsMac;
    $composer = new Composer($mac->shell, $mac->paths, $mac->editor);
    $command = [$mac->bin('composer'), 'global', 'outdated', '--direct', '--format=json'];
    $mac->shell->expect($command, '{"installed": [{"name": "laravel/installer", "version": "v5.1.0", "latest": "v5.2.0"}]}');

    [$entry] = $composer->outdated();

    expect([$entry['package']->key(), $entry['package']->version, $entry['latest']])->toBe(['package:laravel/installer', 'v5.1.0', 'v5.2.0']);

    $mac->shell->expect($command, "[]\n");

    expect($composer->outdated())->toBe([]);
});

/**
 * @param  list<array{package: Package, latest: string}>  $outdated
 * @return list<array{string, ?string, string}> each entry's package key, installed version and latest version
 */
function outdatedRows(array $outdated): array
{
    return array_map(fn (array $entry) => [$entry['package']->key(), $entry['package']->version, $entry['latest']], $outdated);
}

it('lists outdated pnpm globals, and takes the {} pnpm prints for none', function () {
    $mac = new GlobalsMac;
    $pnpm = new Pnpm($mac->shell, $mac->paths, $mac->editor);
    $command = [$mac->bin('pnpm'), 'outdated', '-g', '--format', 'json'];
    $mac->shell->expect($command, '{"typescript": {"current": "6.0.3", "latest": "7.0.2", "wanted": "6.0.3", "isDeprecated": false, "dependencyType": "dependencies"}}', exitCode: 1);

    expect(outdatedRows($pnpm->outdated()))->toBe([['package:typescript', '6.0.3', '7.0.2']]);

    $mac->shell->expect($command, "{}\n");

    expect($pnpm->outdated())->toBe([]);
});

it('lists outdated App Store apps by name, from the columns mas prints', function () {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('mas'), 'outdated'], " 497799835  Xcode        (16.4  -> 26.1)\n1569813296  1Password for Safari  (8.12.4 -> 8.12.46)\n");

    $outdated = (new Mas($mac->shell, $mac->paths, $mac->editor))->outdated();

    expect(outdatedRows($outdated))->toBe([['app:Xcode', '16.4', '26.1'], ['app:1Password for Safari', '8.12.4', '8.12.46']]);
});

it('lists outdated gh extensions by repo, from a dry run of upgrading them all', function () {
    $mac = new GlobalsMac;
    $mac->shell
        ->expect([$mac->bin('gh'), 'extension', 'list'], "gh dash\tdlvhdr/gh-dash\tv4.20.0\ngh branch\tmislav/gh-branch\t0d1bc7e2\n")
        ->expect([$mac->bin('gh'), 'extension', 'upgrade', '--all', '--dry-run'], "[dash]: would have upgraded from v4.20.0 to v4.26.3\n[branch]: already up to date\n");

    $outdated = (new Gh($mac->shell, $mac->paths, $mac->editor))->outdated();

    expect(outdatedRows($outdated))->toBe([['extension:dlvhdr/gh-dash', 'v4.20.0', 'v4.26.3']]);
});

it('lists outdated pipx apps, without what was injected into one', function () {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('pipx'), 'list', '--outdated', '--output', 'json'], json_encode(['data' => ['packages_checked' => 3, 'skipped' => [], 'packages' => [
        ['environment' => 'black', 'package' => 'Black', 'version' => '24.1.0', 'latest_version' => '25.9.0', 'injected' => false, 'pinned' => false],
        ['environment' => 'black', 'package' => 'click', 'version' => '8.1.0', 'latest_version' => '8.3.0', 'injected' => true, 'pinned' => false],
    ]]]));

    $outdated = (new Pipx($mac->shell, $mac->paths, $mac->editor))->outdated();

    expect(outdatedRows($outdated))->toBe([['package:black', '24.1.0', '25.9.0']]);
});

it('lists outdated uv tools, not the current ones or their entry points', function () {
    $mac = new GlobalsMac;
    $mac->shell->expect([$mac->bin('uv'), 'tool', 'list', '--outdated'], "ruff v0.6.9 [latest: 0.14.2]\n- ruff\n");

    $outdated = (new Uv($mac->shell, $mac->paths, $mac->editor))->outdated();

    expect(outdatedRows($outdated))->toBe([['package:ruff', '0.6.9', '0.14.2']]);
});

it('lists outdated crates by searching crates.io, and skips one installed from git', function () {
    $mac = new GlobalsMac;
    $cargo = $mac->bin('cargo');
    $mac->shell
        ->expect([$cargo, 'install', '--list'], "ripgrep v14.1.0:\n    rg\nfd-find v10.2.0:\n    fd\njj-cli v0.30.0 (https://github.com/jj-vcs/jj#4f1a2b3c):\n    jj\n")
        ->expect([$cargo, 'search', '--limit', '1', 'ripgrep'], "ripgrep = \"14.1.1\"    # Line-oriented search tool\n... and 212 crates more (use --limit N to see more)\n")
        ->expect([$cargo, 'search', '--limit', '1', 'fd-find'], "fd-find = \"10.2.0\"    # A simple, fast alternative to find\n");

    $outdated = (new Cargo($mac->shell, $mac->paths, $mac->editor))->outdated();

    expect(outdatedRows($outdated))->toBe([['crate:ripgrep', '14.1.0', '14.1.1']]);
});
