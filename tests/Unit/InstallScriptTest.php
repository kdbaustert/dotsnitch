<?php

declare(strict_types=1);

use App\Data\ProcessResult;
use App\Support\ProcessShell;

/*
 * Runs scripts/install.sh against a release folder on disk instead of GitHub, with a stand-in
 * binary that only answers --version.
 */

beforeEach(function () {
    $this->home = realpath(sys_get_temp_dir()).'/dotsnitch-install-'.bin2hex(random_bytes(4));
    $this->release = "{$this->home}/release";
    mkdir($this->release, 0755, true);

    foreach (['dotsnitch-arm64', 'dotsnitch-x86_64'] as $asset) {
        file_put_contents("{$this->release}/{$asset}", "#!/bin/sh\necho 'Dotsnitch v9.9.9'\n");
    }

    exec('cd '.escapeshellarg($this->release).' && shasum -a 256 dotsnitch-arm64 dotsnitch-x86_64 > checksums.txt');
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->home));
});

/**
 * Runs the installer with a bare environment, reading the release from $home/release. PATH has
 * $home/bin on it but not $home/.local/bin.
 *
 * @param  list<string>  $arguments
 */
function runInstaller(string $home, array $arguments = []): ProcessResult
{
    $script = dirname(__DIR__, 2).'/scripts/install.sh';

    return (new ProcessShell)->run(['/usr/bin/env', '-i', "HOME={$home}", "PATH={$home}/bin:/usr/bin:/bin:/usr/sbin", "DOTSNITCH_RELEASES=file://{$home}/release", '/bin/sh', $script, ...$arguments]);
}

it('installs to ~/.local/bin and says how to put it on PATH', function () {
    $result = runInstaller($this->home);

    expect($result->exitCode)->toBe(0)
        ->and(is_executable("{$this->home}/.local/bin/dotsnitch"))->toBeTrue()
        ->and($result->output)->toContain("Installed Dotsnitch v9.9.9 at {$this->home}/.local/bin/dotsnitch")
        ->and($result->output)->toContain("export PATH=\"{$this->home}/.local/bin:\$PATH\"");
});

it('installs to --prefix and skips the PATH hint when the folder is already on PATH', function () {
    $result = runInstaller($this->home, ["--prefix={$this->home}/bin"]);

    expect($result->exitCode)->toBe(0)
        ->and(is_executable("{$this->home}/bin/dotsnitch"))->toBeTrue()
        ->and($result->output)->not->toContain('export PATH');
});

it('replaces an older install in place', function () {
    mkdir("{$this->home}/.local/bin", 0755, true);
    file_put_contents("{$this->home}/.local/bin/dotsnitch", "#!/bin/sh\necho 'Dotsnitch v1.0.0'\n");

    runInstaller($this->home);

    expect(file_get_contents("{$this->home}/.local/bin/dotsnitch"))->toBe(file_get_contents("{$this->release}/dotsnitch-arm64"))
        ->and(file_exists("{$this->home}/.local/bin/.dotsnitch.new"))->toBeFalse();
});

it('installs nothing when the download does not match checksums.txt', function () {
    file_put_contents("{$this->release}/dotsnitch-arm64", "tampered\n", FILE_APPEND);
    file_put_contents("{$this->release}/dotsnitch-x86_64", "tampered\n", FILE_APPEND);

    $result = runInstaller($this->home);

    expect($result->exitCode)->toBe(1)
        ->and($result->errorOutput)->toContain("doesn't match checksums.txt, so nothing was installed.")
        ->and(file_exists("{$this->home}/.local/bin"))->toBeFalse();
});

it('rejects an option it does not know', function () {
    $result = runInstaller($this->home, ['--yes']);

    expect($result->exitCode)->toBe(1)
        ->and($result->errorOutput)->toBe("Unknown option --yes. The only one is --prefix=DIR.\n");
});
