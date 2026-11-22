<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\Shell;
use RuntimeException;

/**
 * Replaces the standalone binary with the latest release, the way scripts/install.sh installs it.
 */
final class SelfUpdate
{
    public const RELEASES = 'https://github.com/kdbaustert/dotsnitch/releases/latest/download';

    private const DOWNLOAD_TIMEOUT = 300;

    /**
     * @param  string  $releases  where the builds and checksums.txt are, without a trailing slash
     */
    public function __construct(private readonly Shell $shell, private readonly string $releases = self::RELEASES) {}

    /**
     * Downloads next to the binary and renames over it, so a failed download leaves the old one
     * in place and a dotsnitch that's running keeps the file it opened.
     *
     * @param  string  $binary  absolute path of the standalone build to replace
     * @return bool false when the binary already is the latest release
     *
     * @throws RuntimeException when the release can't be downloaded, doesn't match its checksum or can't be put in place
     */
    public function update(string $binary): bool
    {
        $asset = $this->asset();
        $expected = $this->checksumOf($asset);

        if (hash_file('sha256', $binary) === $expected) {
            return false;
        }

        $download = dirname($binary).'/.dotsnitch.new';
        $this->curl(['-o', $download, "{$this->releases}/{$asset}"]);

        if (hash_file('sha256', $download) !== $expected) {
            unlink($download);

            throw new RuntimeException("{$asset} doesn't match its checksum, so nothing was installed.");
        }

        if (! chmod($download, 0755) || ! rename($download, $binary)) {
            throw new RuntimeException("Couldn't replace {$binary}.");
        }

        return true;
    }

    /**
     * sysctl answers for the hardware, so an Intel build running under Rosetta still gets arm64.
     */
    private function asset(): string
    {
        $arm64 = $this->shell->run(['/usr/sbin/sysctl', '-in', 'hw.optional.arm64']);

        return trim($arm64->output) === '1' ? 'dotsnitch-arm64' : 'dotsnitch-x86_64';
    }

    /**
     * @throws RuntimeException when checksums.txt can't be fetched or doesn't list the asset
     */
    private function checksumOf(string $asset): string
    {
        foreach (explode("\n", $this->curl(["{$this->releases}/checksums.txt"])) as $line) {
            $fields = preg_split('/\s+/', trim($line));

            if (count($fields) === 2 && ltrim($fields[1], '*') === $asset) {
                return strtolower($fields[0]);
            }
        }

        throw new RuntimeException("The release has no checksum for {$asset}.");
    }

    /**
     * @param  list<string>  $arguments
     * @return string what curl printed
     *
     * @throws RuntimeException when the download fails
     */
    private function curl(array $arguments): string
    {
        $result = $this->shell->run(['/usr/bin/curl', '-fsSL', ...$arguments], self::DOWNLOAD_TIMEOUT);

        if (! $result->successful()) {
            throw new RuntimeException('The download failed: '.(trim($result->errorOutput) ?: "curl exited with code {$result->exitCode}"));
        }

        return $result->output;
    }
}
