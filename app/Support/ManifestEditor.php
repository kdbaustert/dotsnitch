<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use RuntimeException;

/**
 * Writes manifest edits for one run. Each file is copied into this run's backup folder before
 * its first change, and a symlinked manifest is edited where it really lives. Files a link
 * replaces are moved into the same folder.
 */
final class ManifestEditor
{
    /** @var array<string, true> real paths changed this run, in order */
    private array $changed = [];

    /**
     * @param  string  $backups  this run's folder, such as ~/.cache/dotsnitch/backups/2026-11-21-093000; created on the first backup
     */
    public function __construct(private readonly string $backups) {}

    /**
     * Leaves the file alone when $change returns what it was given.
     *
     * @param  Closure(string): string  $change  gets the current contents, '' for a file that doesn't exist yet
     *
     * @throws RuntimeException when the file can't be read, backed up or written
     */
    public function edit(string $path, Closure $change): void
    {
        $path = AtomicFile::resolve($path);
        $exists = is_file($path);
        $before = $exists ? file_get_contents($path) : '';

        if ($before === false) {
            throw new RuntimeException("Could not read {$path}");
        }

        $after = $change($before);

        if ($after === $before) {
            return;
        }

        if ($exists && ! isset($this->changed[$path])) {
            $this->backUp($path);
        }

        AtomicFile::write($path, $after);
        $this->changed[$path] = true;
    }

    /**
     * @return list<string> real paths, in the order they were first changed
     */
    public function changed(): array
    {
        return array_keys($this->changed);
    }

    /**
     * Where $path goes in the backup folder. The full path is kept, so two files named Brewfile
     * don't collide.
     */
    public function backupOf(string $path): string
    {
        return $this->backups.$path;
    }

    /**
     * Moves a file or folder out of the way, such as one a symlink is about to replace.
     *
     * @throws RuntimeException when the move fails
     */
    public function moveToBackups(string $path): void
    {
        $destination = $this->freeBackupOf($path);
        $this->makeParentOf($destination);

        if (! rename($path, $destination)) {
            throw new RuntimeException("Could not move {$path} to {$destination}");
        }
    }

    /**
     * @throws RuntimeException when the copy fails
     */
    private function backUp(string $path): void
    {
        $copy = $this->freeBackupOf($path);
        $this->makeParentOf($copy);

        if (! copy($path, $copy)) {
            throw new RuntimeException("Could not back up {$path} to {$copy}");
        }
    }

    /**
     * backupOf(), with .1, .2 and so on after it when that's taken, so a backup never replaces
     * another. An adopt after an edit backs up the same file twice, and two runs that start in
     * the same second share a folder.
     */
    private function freeBackupOf(string $path): string
    {
        $base = $this->backupOf($path);
        $candidate = $base;

        for ($number = 1; file_exists($candidate) || is_link($candidate); $number++) {
            $candidate = "{$base}.{$number}";
        }

        return $candidate;
    }

    /**
     * @throws RuntimeException when the folder can't be created
     */
    private function makeParentOf(string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}");
        }
    }
}
