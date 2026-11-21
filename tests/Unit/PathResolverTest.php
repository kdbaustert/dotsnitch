<?php

declare(strict_types=1);

use App\Support\AmbiguousHomebrew;
use App\Support\PathResolver;
use App\Support\UserSettings;
use Tests\Fakes\FakeShell;

const LOGIN_COMMAND = ['/bin/zsh', '+m', '-lic', 'printf __DOTSNITCH_ENV__; /usr/bin/env -0'];

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    mkdir("{$this->dir}/home", 0755, true);
    $this->settings = new UserSettings("{$this->dir}/config.json");
    $this->inherited = ['SHELL' => '/bin/zsh', 'HOME' => "{$this->dir}/home", 'PATH' => '/usr/bin:/bin'];
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * Writes an empty sh script, enough to pass an is_executable() check.
 */
function makeExecutable(string $path): string
{
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, "#!/bin/sh\n");
    chmod($path, 0755);

    return $path;
}

/**
 * What the login shell prints: any banner from .zshrc, the marker, then `env -0` output.
 *
 * @param  array<string, string>  $variables
 */
function loginOutput(array $variables, string $banner = ''): string
{
    $entries = array_map(fn ($name, $value) => "{$name}={$value}", array_keys($variables), $variables);

    return $banner.'__DOTSNITCH_ENV__'.implode("\0", $entries)."\0";
}

it('puts Homebrew first and existing tool bin dirs last on the login PATH', function () {
    makeExecutable("{$this->dir}/brew/bin/brew");
    mkdir("{$this->dir}/home/.cargo/bin", 0755, true);
    $shell = (new FakeShell)->expect(LOGIN_COMMAND, loginOutput([
        'PATH' => '/usr/bin:/bin',
        'NVM_DIR' => '/Users/me/.nvm',
        'SHLVL' => '2',
    ], banner: "Welcome back\n"));

    $environment = (new PathResolver($shell, $this->settings, $this->inherited, ["{$this->dir}/brew"]))->homeEnvironment();

    expect($environment['PATH'])->toBe(implode(':', [
        "{$this->dir}/brew/bin",
        "{$this->dir}/brew/sbin",
        '/usr/bin',
        '/bin',
        "{$this->dir}/home/.cargo/bin",
    ]))
        ->and($environment['NVM_DIR'])->toBe('/Users/me/.nvm')
        ->and($environment)->not->toHaveKey('SHLVL');
});

it('falls back to the inherited environment when the login shell fails', function () {
    $shell = (new FakeShell)->expect(LOGIN_COMMAND, exitCode: 1);
    $resolver = new PathResolver($shell, $this->settings, $this->inherited, []);

    expect($resolver->homeEnvironment()['PATH'])->toBe('/usr/bin:/bin')
        ->and($resolver->warnings())->toBe(["Couldn't read the environment from /bin/zsh; using the inherited PATH."]);
});

it('uses the brew found on PATH before probing the standard prefixes', function () {
    $brew = makeExecutable("{$this->dir}/custom/bin/brew");
    makeExecutable("{$this->dir}/opt/bin/brew");
    $shell = (new FakeShell)
        ->expect(LOGIN_COMMAND, loginOutput(['PATH' => "{$this->dir}/custom/bin"]))
        ->expect([$brew, '--prefix'], "{$this->dir}/custom\n");

    $resolver = new PathResolver($shell, $this->settings, $this->inherited, ["{$this->dir}/opt"]);

    expect($resolver->homebrewPrefix())->toBe("{$this->dir}/custom");
});

it('probes the standard prefixes when the brew on PATH hangs, and says so', function () {
    $brew = makeExecutable("{$this->dir}/custom/bin/brew");
    makeExecutable("{$this->dir}/opt/bin/brew");
    $shell = (new FakeShell)
        ->expect(LOGIN_COMMAND, loginOutput(['PATH' => "{$this->dir}/custom/bin"]))
        ->expectTimeout([$brew, '--prefix']);

    $resolver = new PathResolver($shell, $this->settings, $this->inherited, ["{$this->dir}/opt"]);

    expect($resolver->homebrewPrefix())->toBe("{$this->dir}/opt")
        ->and($resolver->warnings())->toBe(["`{$brew} --prefix` took over 15s; looked in the standard prefixes instead."]);
});

it('asks for a choice when both prefixes have brew and none is on PATH', function () {
    makeExecutable("{$this->dir}/opt/bin/brew");
    makeExecutable("{$this->dir}/local/bin/brew");
    $shell = (new FakeShell)->expect(LOGIN_COMMAND, loginOutput(['PATH' => '/usr/bin']));
    $resolver = new PathResolver($shell, $this->settings, $this->inherited, ["{$this->dir}/opt", "{$this->dir}/local"]);

    expect(fn () => $resolver->homebrewPrefix())->toThrow(AmbiguousHomebrew::class);

    $resolver->chooseHomebrewPrefix("{$this->dir}/local");

    expect($resolver->homebrewPrefix())->toBe("{$this->dir}/local")
        ->and($this->settings->get('homebrew.prefix'))->toBe("{$this->dir}/local");
});

it('names the version manager that owns node', function () {
    $node = makeExecutable("{$this->dir}/home/.nvm/versions/node/v22.11.0/bin/node");
    $environment = ['PATH' => dirname($node), 'HOME' => "{$this->dir}/home"];
    $shell = (new FakeShell)
        ->expect(LOGIN_COMMAND, loginOutput($environment))
        ->expect([$node, '--version'], "v22.11.0\n");

    $resolver = new PathResolver($shell, $this->settings, $this->inherited, []);

    expect($resolver->nodeDescription())->toBe('Node 22.11.0 via nvm');
});

it('says the version is unknown when node --version fails or hangs', function () {
    $node = makeExecutable("{$this->dir}/home/.nvm/versions/node/v22.11.0/bin/node");
    $login = loginOutput(['PATH' => dirname($node), 'HOME' => "{$this->dir}/home"]);
    $failing = (new FakeShell)->expect(LOGIN_COMMAND, $login)->expect([$node, '--version'], exitCode: 1);
    $hanging = (new FakeShell)->expect(LOGIN_COMMAND, $login)->expectTimeout([$node, '--version']);

    expect((new PathResolver($failing, $this->settings, $this->inherited, []))->nodeDescription())->toBe('Node (version unknown) via nvm')
        ->and((new PathResolver($hanging, $this->settings, $this->inherited, []))->nodeDescription())->toBe('Node (version unknown) via nvm');
});

it('drops per-process variables from the inherited fallback', function () {
    $shell = (new FakeShell)->expect(LOGIN_COMMAND, exitCode: 1);
    $inherited = [...$this->inherited, 'PWD' => '/somewhere', 'SHLVL' => '3', '_' => '/usr/bin/php'];

    $environment = (new PathResolver($shell, $this->settings, $inherited, []))->homeEnvironment();

    expect($environment)->not->toHaveKeys(['PWD', 'SHLVL', '_'])
        ->and($environment['SHELL'])->toBe('/bin/zsh');
});

it('skips a tool that hangs and says so', function () {
    $composer = makeExecutable("{$this->dir}/bin/composer");
    $shell = (new FakeShell)
        ->expect(LOGIN_COMMAND, loginOutput(['PATH' => "{$this->dir}/bin", 'HOME' => "{$this->dir}/home"]))
        ->expectTimeout([$composer, 'global', 'config', 'bin-dir', '--absolute']);

    $resolver = new PathResolver($shell, $this->settings, $this->inherited, []);

    expect($resolver->homeEnvironment()['PATH'])->toBe("{$this->dir}/bin")
        ->and($resolver->warnings())->toBe(["`{$composer} global config bin-dir --absolute` took over 15s; skipped its bin dir."]);
});

it("doesn't call a /usr/local node Homebrew's when no Homebrew lives there", function () {
    $node = makeExecutable("{$this->dir}/local/bin/node");
    $shell = (new FakeShell)
        ->expect(LOGIN_COMMAND, loginOutput(['PATH' => dirname($node), 'HOME' => "{$this->dir}/home"]))
        ->expect([$node, '--version'], "v22.11.0\n");

    $resolver = new PathResolver($shell, $this->settings, $this->inherited, ["{$this->dir}/local"]);

    expect($resolver->nodeDescription())->toBe('Node 22.11.0');
});

it('runs brew --prefix once per run', function () {
    $brew = makeExecutable("{$this->dir}/custom/bin/brew");
    $shell = (new FakeShell)
        ->expect(LOGIN_COMMAND, loginOutput(['PATH' => "{$this->dir}/custom/bin"]))
        ->expect([$brew, '--prefix'], "{$this->dir}/custom\n");
    $resolver = new PathResolver($shell, $this->settings, $this->inherited, []);

    $resolver->homebrewPrefix();
    $resolver->homebrewPrefix();

    expect(array_filter($shell->ran(), fn (array $command) => $command === [$brew, '--prefix']))->toHaveCount(1);
});
