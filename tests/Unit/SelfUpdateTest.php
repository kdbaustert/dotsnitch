<?php

declare(strict_types=1);

use App\Support\ProcessShell;
use App\Support\SelfUpdate;

/*
 * Updates a stand-in binary from a release folder on disk instead of GitHub.
 */

beforeEach(function () {
    $this->dir = realpath(sys_get_temp_dir()).'/dotsnitch-update-'.bin2hex(random_bytes(4));
    $this->binary = "{$this->dir}/bin/dotsnitch";
    mkdir("{$this->dir}/bin", 0755, true);
    mkdir("{$this->dir}/release");
    file_put_contents($this->binary, 'old');

    foreach (['dotsnitch-arm64', 'dotsnitch-x86_64'] as $asset) {
        file_put_contents("{$this->dir}/release/{$asset}", 'new');
    }

    exec('cd '.escapeshellarg("{$this->dir}/release").' && shasum -a 256 dotsnitch-arm64 dotsnitch-x86_64 > checksums.txt');
    $this->update = new SelfUpdate(new ProcessShell, "file://{$this->dir}/release");
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('replaces the binary with the release build and makes it executable', function () {
    expect($this->update->update($this->binary))->toBeTrue()
        ->and(file_get_contents($this->binary))->toBe('new')
        ->and(is_executable($this->binary))->toBeTrue()
        ->and(file_exists("{$this->dir}/bin/.dotsnitch.new"))->toBeFalse();
});

it('downloads nothing when the binary already is the release', function () {
    file_put_contents($this->binary, 'new');
    unlink("{$this->dir}/release/dotsnitch-arm64");
    unlink("{$this->dir}/release/dotsnitch-x86_64");

    expect($this->update->update($this->binary))->toBeFalse();
});

it('keeps the old binary when the download doesn\'t match its checksum', function () {
    file_put_contents("{$this->dir}/release/dotsnitch-arm64", 'tampered');
    file_put_contents("{$this->dir}/release/dotsnitch-x86_64", 'tampered');

    expect(fn () => $this->update->update($this->binary))->toThrow(RuntimeException::class, "doesn't match its checksum")
        ->and(file_get_contents($this->binary))->toBe('old')
        ->and(file_exists("{$this->dir}/bin/.dotsnitch.new"))->toBeFalse();
});

it('keeps the old binary when the release can\'t be reached', function () {
    $update = new SelfUpdate(new ProcessShell, "file://{$this->dir}/nowhere");

    expect(fn () => $update->update($this->binary))->toThrow(RuntimeException::class, 'The download failed: ')
        ->and(file_get_contents($this->binary))->toBe('old');
});

it('throws when the release lists no checksum for this Mac', function () {
    file_put_contents("{$this->dir}/release/checksums.txt", "abc  something-else\n");

    expect(fn () => $this->update->update($this->binary))->toThrow(RuntimeException::class, 'The release has no checksum for dotsnitch-');
});

it('says the release is older only when version.txt names a lower version', function (?string $release, string $running, bool $older) {
    if ($release !== null) {
        file_put_contents("{$this->dir}/release/version.txt", "{$release}\n");
    }

    expect($this->update->releaseIsOlderThan($running))->toBe($older);
})->with([
    'a beta after the release' => ['v0.1.0', 'v0.2.0-beta.1', true],
    'a beta of the release' => ['v0.1.0', 'v0.1.0-beta.2', false],
    'the release itself' => ['v0.1.0', 'v0.1.0', false],
    'an older release' => ['v0.2.0', 'v0.1.0', false],
    'a release with no version.txt' => [null, 'v0.2.0-beta.1', false],
]);

it('removes the download when it can\'t be put in place', function () {
    // Locked the way Finder locks a file, so nothing can be renamed over it.
    exec('chflags uchg '.escapeshellarg($this->binary));
    // PHPUnit reports rename()'s warning even under @, so it's silenced for this one call.
    set_error_handler(fn () => true);

    try {
        expect(fn () => $this->update->update($this->binary))->toThrow(RuntimeException::class, "Couldn't replace {$this->binary}.")
            ->and(file_get_contents($this->binary))->toBe('old')
            ->and(file_exists("{$this->dir}/bin/.dotsnitch.new"))->toBeFalse();
    } finally {
        restore_error_handler();
        exec('chflags nouchg '.escapeshellarg($this->binary));
    }
});
