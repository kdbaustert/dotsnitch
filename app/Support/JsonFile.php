<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;
use RuntimeException;

final class JsonFile
{
    /**
     * @return ?array<mixed> null when the file doesn't exist or is empty
     *
     * @throws RuntimeException when the file exists but can't be read, or isn't a JSON object or array
     */
    public static function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        // Silenced, since `status --quiet` reads the report before Laravel boots and PHP would
        // print the warning into the shell prompt.
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Could not read {$path}");
        }

        if (trim($contents) === '') {
            return null;
        }

        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON in {$path}: {$e->getMessage()}", previous: $e);
        }

        if (! is_array($data)) {
            throw new RuntimeException("Expected a JSON object in {$path}");
        }

        return $data;
    }

    /**
     * Written atomically and through symlinks (see AtomicFile), so a reader such as a shell prompt
     * calling `status --quiet` never sees half a file, and a settings file kept in ~/dotfiles
     * stays linked.
     *
     * @param  array<mixed>  $data
     *
     * @throws RuntimeException when the file can't be written
     */
    public static function write(string $path, array $data): void
    {
        AtomicFile::write($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * Deletes the oldest .json files in a folder of files named by time, leaving the newest $kept.
     */
    public static function keepNewest(string $directory, int $kept): void
    {
        $files = glob("{$directory}/*.json") ?: [];
        sort($files);

        foreach (array_slice($files, 0, max(count($files) - $kept, 0)) as $old) {
            unlink($old);
        }
    }
}
