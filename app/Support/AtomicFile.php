<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class AtomicFile
{
    /** The same limit macOS puts on symlink chains (MAXSYMLINKS). */
    private const MAX_LINK_HOPS = 32;

    /**
     * Writes to a temp file in the same directory and renames it over the target, so a reader
     * never sees half a file. A symlinked path is written through to its target, and an existing
     * file keeps its permissions.
     *
     * @throws RuntimeException when the directory or file can't be written
     */
    public static function write(string $path, string $contents): void
    {
        $path = self::resolve($path);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}");
        }

        // tempnam() creates the file as 0600, which would stick after the rename.
        $mode = is_file($path) ? fileperms($path) & 0777 : 0666 & ~umask();
        $temp = tempnam($directory, '.'.basename($path).'.');

        if ($temp === false || file_put_contents($temp, $contents) === false || ! chmod($temp, $mode) || ! rename($temp, $path)) {
            throw new RuntimeException("Could not write {$path}");
        }
    }

    /**
     * Follows symlinks to the file they point at. Unlike realpath(), this also resolves a link
     * whose target doesn't exist yet.
     */
    public static function resolve(string $path): string
    {
        for ($hops = 0; is_link($path) && $hops < self::MAX_LINK_HOPS; $hops++) {
            $target = (string) readlink($path);
            $path = str_starts_with($target, '/') ? $target : dirname($path).'/'.$target;
        }

        return $path;
    }
}
