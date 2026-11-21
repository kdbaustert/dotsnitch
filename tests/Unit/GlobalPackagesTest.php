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
use Tests\Fakes\FakeShell;

/**
 * A temp home with fake composer, npm, pnpm, mas, gh, pipx, uv and cargo on PATH. npm is a symlink into
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

        foreach (['bin/composer', 'bin/pnpm', 'bin/mas', 'bin/gh', 'bin/pipx', 'bin/uv', 'bin/cargo', 'lib/node_modules/npm/bin/npm-cli.js'] as $file) {
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
        ['untracked', 'extension:github/gh-copilot', null],
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
