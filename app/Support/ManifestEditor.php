<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use UnexpectedValueException;

/**
 * Writes manifest edits for one run. Each file is copied into this run's backup folder before
 * its first change, and a symlinked manifest is edited where it really lives. Files a link
 * replaces are moved into the same folder.
 */
final class ManifestEditor
{
    /** As many as SyncLog keeps records, and every run with a backup has a record that names it. */
    private const RUNS_KEPT = 30;

    /** @var array<string, true> real paths changed this run, in order */
    private array $changed = [];

    /** @var array<string, ?string> the backup copy of each file edit() has changed, null for one it created */
    private array $copies = [];

    /**
     * @param  string  $backups  this run's folder, such as ~/.local/state/dotsnitch/backups/2026-11-21-093000; created on the first backup
     */
    public function __construct(private readonly string $backups) {}

    /**
     * Leaves the file alone when $change returns what it was given.
     *
     * @param  Closure(string): string  $change  gets the current contents, '' for a file that doesn't exist yet
     * @return array<string, mixed> SyncLog's edit shape, or empty when nothing changed
     *
     * @throws RuntimeException when the file can't be read, backed up or written
     */
    public function edit(string $path, Closure $change): array
    {
        $path = AtomicFile::resolve($path);
        $exists = is_file($path);
        $before = $exists ? @file_get_contents($path) : '';

        if ($before === false) {
            throw new RuntimeException("Could not read {$path}");
        }

        $after = $change($before);

        if ($after === $before) {
            return [];
        }

        // Not $changed: a file adopted earlier in the run is in there with no copy of what it holds now.
        if (! array_key_exists($path, $this->copies)) {
            $this->copies[$path] = $exists ? $this->backUp($path) : null;
        }

        AtomicFile::write($path, $after);
        $this->changed[$path] = true;

        return ['kind' => 'edit', 'path' => $path, 'backup' => $this->copies[$path], 'sha256' => hash('sha256', $after)];
    }

    /**
     * Counts a file changed without edit(), such as one adopted into the repo, so changed()
     * lists it too.
     */
    public function markChanged(string $path): void
    {
        $this->changed[AtomicFile::resolve($path)] = true;
    }

    /**
     * @return list<string> real paths, in the order they were first changed
     */
    public function changed(): array
    {
        return array_keys($this->changed);
    }

    /**
     * @return ?string this run's backup folder, null while nothing is in it
     */
    public function backupFolder(): ?string
    {
        return is_dir($this->backups) ? $this->backups : null;
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
     * @return string where it went, which isn't backupOf() when that was taken
     *
     * @throws RuntimeException when the move fails
     */
    public function moveToBackups(string $path): string
    {
        $destination = $this->freeBackupOf($path);
        AtomicFile::makeParentOf($destination);

        if (! @rename($path, $destination)) {
            throw new RuntimeException("Could not move {$path} to {$destination}");
        }

        $this->dropOldRuns();

        return $destination;
    }

    /**
     * @return string where the copy is
     *
     * @throws RuntimeException when the copy fails
     */
    private function backUp(string $path): string
    {
        $copy = $this->freeBackupOf($path);
        AtomicFile::makeParentOf($copy);

        if (! @copy($path, $copy)) {
            throw new RuntimeException("Could not back up {$path} to {$copy}");
        }

        $this->dropOldRuns();

        return $copy;
    }

    /**
     * Deletes the oldest runs' folders next to this one, leaving the newest RUNS_KEPT. Only
     * folders named like a run's start time count. One that can't be read or deleted is left.
     */
    private function dropOldRuns(): void
    {
        $runs = array_filter(
            glob(dirname($this->backups).'/*', GLOB_ONLYDIR) ?: [],
            fn (string $run) => preg_match('/^\d{4}-\d{2}-\d{2}-\d{6}$/', basename($run)) === 1,
        );
        sort($runs);

        foreach (array_slice($runs, 0, max(count($runs) - self::RUNS_KEPT, 0)) as $old) {
            try {
                // Links aren't followed, so a backed-up link's destination is never touched.
                $inside = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($old, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST,
                );

                foreach ($inside as $entry) {
                    $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
                }
            } catch (UnexpectedValueException) {
                // A folder in it can't be opened. The backup this run just made still counts.
                continue;
            }

            @rmdir($old);
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
}
