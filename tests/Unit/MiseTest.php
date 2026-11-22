<?php

declare(strict_types=1);

use App\Support\PathResolver;
use App\Support\UserSettings;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftKind;
use App\Targets\Mise;
use Tests\Fakes\FakeShell;

// A stand-in `mise` on PATH, so the commands are the same on any machine and never run.
beforeEach(function () {
    $dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    mkdir("{$dir}/bin", 0755, true);
    $this->dir = realpath($dir);
    $this->mise = "{$this->dir}/bin/mise";
    file_put_contents($this->mise, "#!/bin/sh\n");
    chmod($this->mise, 0755);
    $this->config = "{$this->dir}/config.toml";
    file_put_contents($this->config, "[tools]\nnode = \"22\"\nusage = \"latest\"\n");
    file_put_contents("{$this->dir}/mise.toml", "[tools]\njq = \"latest\"\n");

    $settings = new UserSettings("{$this->dir}/config.json");
    // An empty prefix, so PathResolver doesn't go looking through the real Homebrew.
    mkdir("{$this->dir}/brew/bin", 0755, true);
    file_put_contents("{$this->dir}/brew/bin/brew", "#!/bin/sh\n");
    chmod("{$this->dir}/brew/bin/brew", 0755);
    $settings->set('homebrew.prefix', "{$this->dir}/brew");
    $inherited = ['SHELL' => '/bin/zsh', 'HOME' => $this->dir, 'PATH' => "{$this->dir}/bin"];
    $login = (new FakeShell)->expect(['/bin/zsh', '+m', '-lic', 'printf __DOTSNITCH_ENV__; /usr/bin/env -0'], exitCode: 1);
    $this->shell = new FakeShell;
    $this->check = new Mise($this->shell, new PathResolver($login, $settings, $inherited));
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * `mise ls --missing --json` as mise 2026.10 prints it.
 *
 * @param  array<string, array{string, string}>  $tools  tool => [resolved version, the config that asks for it]
 */
function miseListing(array $tools): string
{
    return json_encode(array_map(fn (array $tool) => [[
        'version' => $tool[0],
        'requested_version' => 'latest',
        'source' => ['type' => 'mise.toml', 'path' => $tool[1]],
        'installed' => false,
        'active' => false,
    ]], $tools));
}

it('reports the tools the config asks for that are not installed', function () {
    $this->shell->expect([$this->mise, 'ls', '--missing', '--json'], miseListing([
        'node' => ['22.23.5', $this->config],
        'npm:prettier' => ['3.10.1', $this->config],
        'jq' => ['1.8.1', "{$this->dir}/mise.toml"],
    ]));

    $items = $this->check->scan([$this->config], fn () => null)->items;

    expect(array_map(fn ($item) => [$item->kind, $item->subject->key(), $item->subject->version], $items))->toBe([
        [DriftKind::Missing, 'mise:node', '22.23.5'],
        [DriftKind::Missing, 'mise:npm:prettier', '3.10.1'],
    ])
        ->and($this->shell->environmentOf([$this->mise, 'ls', '--missing', '--json']))->toBe(['MISE_GLOBAL_CONFIG_FILE' => $this->config])
        ->and(array_keys($this->check->actionsFor($items[0])))->toBe(['install', 'ignore', 'skip']);
});

it('reports nothing when mise has everything', function () {
    $this->shell->expect([$this->mise, 'ls', '--missing', '--json'], '{}');

    expect($this->check->scan([$this->config], fn () => null)->items)->toBe([]);
});

it('stops when mise cannot read the config', function () {
    $this->shell->expect([$this->mise, 'ls', '--missing', '--json'], exitCode: 1, errorOutput: "mise ERROR nosuch not found in mise tool registry\n");

    expect(fn () => $this->check->scan([$this->config], fn () => null))
        ->toThrow(RuntimeException::class, 'nosuch not found in mise tool registry');
});

it('installs the version the scan resolved, and records the uninstall that undoes it', function () {
    $install = [$this->mise, 'install', 'usage@7.4.0'];
    $this->shell
        ->expect([$this->mise, 'ls', '--missing', '--json'], miseListing(['usage' => ['7.4.0', $this->config]]))
        ->expect($install);

    [$item] = $this->check->scan([$this->config], fn () => null)->items;
    [$step] = $this->check->steps(new Decision($item, Action::Install));

    expect($step->description)->toBe("{$this->mise} install usage@7.4.0")
        ->and(($step->run)())->toBe([
            'kind' => 'command',
            'command' => $install,
            'undo' => [[$this->mise, 'uninstall', 'usage@7.4.0']],
            'environment' => ['MISE_GLOBAL_CONFIG_FILE' => $this->config],
        ])
        ->and($this->shell->environmentOf($install))->toBe(['MISE_GLOBAL_CONFIG_FILE' => $this->config]);
});

it('fails the step when the install does', function () {
    $this->shell
        ->expect([$this->mise, 'ls', '--missing', '--json'], miseListing(['usage' => ['7.4.0', $this->config]]))
        ->expect([$this->mise, 'install', 'usage@7.4.0'], exitCode: 1, errorOutput: "mise ERROR no network\n");

    [$item] = $this->check->scan([$this->config], fn () => null)->items;
    [$step] = $this->check->steps(new Decision($item, Action::Install));

    expect(fn () => ($step->run)())->toThrow(RuntimeException::class, 'mise ERROR no network');
});
