<?php

declare(strict_types=1);

use App\Support\ManifestEditor;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    mkdir("{$this->dir}/repo", 0755, true);
    $this->dir = realpath($this->dir);
    $this->editor = new ManifestEditor("{$this->dir}/backups");
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('backs up a file once, before its first change', function () {
    $brewfile = "{$this->dir}/repo/Brewfile";
    file_put_contents($brewfile, "brew \"jq\"\n");

    $this->editor->edit($brewfile, fn (string $contents) => $contents."brew \"mpv\"\n");
    $this->editor->edit($brewfile, fn (string $contents) => $contents."cask \"zed\"\n");

    expect(file_get_contents($brewfile))->toBe("brew \"jq\"\nbrew \"mpv\"\ncask \"zed\"\n")
        ->and(file_get_contents("{$this->dir}/backups{$brewfile}"))->toBe("brew \"jq\"\n")
        ->and($this->editor->backupFolder())->toBe("{$this->dir}/backups")
        ->and($this->editor->changed())->toBe([$brewfile]);
});

it('edits the real file behind a symlink and keeps its permissions', function () {
    $real = "{$this->dir}/repo/Brewfile";
    file_put_contents($real, "brew \"jq\"\n");
    chmod($real, 0640);
    symlink($real, "{$this->dir}/Brewfile");

    $this->editor->edit("{$this->dir}/Brewfile", fn () => "brew \"mpv\"\n");

    expect(is_link("{$this->dir}/Brewfile"))->toBeTrue()
        ->and(file_get_contents($real))->toBe("brew \"mpv\"\n")
        ->and(fileperms($real) & 0777)->toBe(0640)
        ->and($this->editor->changed())->toBe([$real]);
});

it('creates a new file without a backup and leaves an unchanged one alone', function () {
    $overlay = "{$this->dir}/repo/Brewfile.mac";
    $brewfile = "{$this->dir}/repo/Brewfile";
    file_put_contents($brewfile, "brew \"jq\"\n");

    $this->editor->edit($overlay, fn (string $contents) => $contents."brew \"mpv\"\n");
    $this->editor->edit($brewfile, fn (string $contents) => $contents);

    expect(file_get_contents($overlay))->toBe("brew \"mpv\"\n")
        ->and(is_dir("{$this->dir}/backups"))->toBeFalse()
        ->and($this->editor->backupFolder())->toBeNull()
        ->and($this->editor->changed())->toBe([$overlay]);
});

it("returns each edit's backup and a hash of what it wrote", function () {
    $brewfile = "{$this->dir}/repo/Brewfile";
    $backup = "{$this->dir}/backups{$brewfile}";
    file_put_contents($brewfile, "brew \"jq\"\n");

    expect($this->editor->edit($brewfile, fn () => "brew \"mpv\"\n"))
        ->toBe(['kind' => 'edit', 'path' => $brewfile, 'backup' => $backup, 'sha256' => hash('sha256', "brew \"mpv\"\n")])
        ->and($this->editor->edit($brewfile, fn () => ''))
        ->toBe(['kind' => 'edit', 'path' => $brewfile, 'backup' => $backup, 'sha256' => hash('sha256', '')])
        ->and($this->editor->edit("{$brewfile}.mac", fn () => "brew \"jq\"\n")['backup'])->toBeNull()
        ->and($this->editor->edit($brewfile, fn (string $contents) => $contents))->toBe([]);
});

it('backs up a file adopted earlier in the run before editing it', function () {
    $zshrc = "{$this->dir}/repo/.zshrc";
    file_put_contents($zshrc, 'adopted');
    $this->editor->markChanged($zshrc);

    $effect = $this->editor->edit($zshrc, fn () => 'edited');

    expect($effect['backup'])->toBe("{$this->dir}/backups{$zshrc}")
        ->and(file_get_contents($effect['backup']))->toBe('adopted');
});

it('leaves no temp file behind when the write fails', function () {
    mkdir("{$this->dir}/repo/Brewfile");
    // PHPUnit reports rename()'s warning even under @, so it's silenced for this one call.
    set_error_handler(fn () => true);

    try {
        expect(fn () => $this->editor->edit("{$this->dir}/repo/Brewfile", fn () => "brew \"jq\"\n"))
            ->toThrow(RuntimeException::class, "Could not write {$this->dir}/repo/Brewfile");
    } finally {
        restore_error_handler();
    }

    expect(scandir("{$this->dir}/repo"))->toBe(['.', '..', 'Brewfile']);
});

it('moves a folder into backups, keeping its full path', function () {
    mkdir("{$this->dir}/home/.config/nvim", recursive: true);
    file_put_contents("{$this->dir}/home/.config/nvim/init.lua", 'vim.o.number = true');

    $this->editor->moveToBackups("{$this->dir}/home/.config/nvim");

    expect(file_exists("{$this->dir}/home/.config/nvim"))->toBeFalse()
        ->and(file_get_contents("{$this->dir}/backups{$this->dir}/home/.config/nvim/init.lua"))->toBe('vim.o.number = true');
});

it('numbers a backup instead of replacing one already there', function () {
    $zshrc = "{$this->dir}/repo/.zshrc";
    file_put_contents($zshrc, 'old');

    $this->editor->edit($zshrc, fn () => 'edited');
    $moved = $this->editor->moveToBackups($zshrc);

    expect($moved)->toBe("{$this->dir}/backups{$zshrc}.1")
        ->and(file_exists($zshrc))->toBeFalse()
        ->and(file_get_contents("{$this->dir}/backups{$zshrc}"))->toBe('old')
        ->and(file_get_contents("{$this->dir}/backups{$zshrc}.1"))->toBe('edited');
});
