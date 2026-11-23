<?php

declare(strict_types=1);

namespace App\Support;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds lines that look like they hold a token, key or password, so they can be flagged before
 * they go into the dotfiles repo. It's only a pattern match: it misses some secrets and can flag
 * a placeholder.
 */
final class SecretScanner
{
    /** Config files are far smaller. A bigger file is likely data, and it's skipped. */
    private const MAX_BYTES = 1_000_000;

    /**
     * Tried in order, and the first match names the line. The last one leaves out a value that
     * starts like a variable, command, template, path or env lookup, such as ${NPM_TOKEN},
     * ~/.password-store or process.env.TOKEN. It also leaves out code: ::, =>, == and values with
     * brackets in them.
     */
    private const PATTERNS = [
        'a private key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'a GitHub token' => '/\b(?:gh[pousr]_[A-Za-z0-9]{36}|github_pat_\w{22,})/',
        'an npm token' => '/_authToken\s*=\s*["\']?(?![$<{])\S/',
        'an AWS access key' => '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
        'a Slack token' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}/',
        'a password or token' => '/\b\w*(?:password|passwd|secret|token|api_?key)["\']?\s*(?<![=:!<>])[=:](?![=:>])\s*["\']?'
            .'(?![$<{%~\/`]|process\.env|import\.meta|os\.environ)[^\s"\'#()<>\[\]{},;]{8,}(?![^\s"\'#,;])/i',
    ];

    /**
     * A folder is read all the way down, leaving out .git, binary files and links to folders.
     *
     * @return list<array{path: string, line: int, kind: string}> one for each line that matched, in file order
     */
    public function scan(string $path): array
    {
        $secrets = [];

        foreach ($this->filesIn($path) as $file) {
            array_push($secrets, ...$this->secretsIn($file));
        }

        return $secrets;
    }

    /**
     * @return list<string> empty when nothing is there
     */
    private function filesIn(string $path): array
    {
        if (! is_dir($path)) {
            return is_file($path) ? [$path] : [];
        }

        $folder = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $file) => $file->getFilename() !== '.git',
        );
        $files = [];

        // A subfolder it can't read is skipped, since the scan only adds a warning.
        foreach (new RecursiveIteratorIterator($folder, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<array{path: string, line: int, kind: string}>
     */
    private function secretsIn(string $file): array
    {
        if (! is_readable($file) || filesize($file) > self::MAX_BYTES) {
            return [];
        }

        $text = file_get_contents($file);

        if ($text === false || str_contains($text, "\0")) {
            return [];
        }

        $secrets = [];

        foreach (explode("\n", $text) as $index => $line) {
            foreach (self::PATTERNS as $kind => $pattern) {
                if (preg_match($pattern, $line)) {
                    $secrets[] = ['path' => $file, 'line' => $index + 1, 'kind' => $kind];

                    break;
                }
            }
        }

        return $secrets;
    }
}
