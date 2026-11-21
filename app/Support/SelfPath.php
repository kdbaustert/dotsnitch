<?php

declare(strict_types=1);

namespace App\Support;

use Phar;

final class SelfPath
{
    /**
     * How to run this same dotsnitch again from launchd or a background refresh, where PATH is
     * bare. Every path is absolute with symlinks resolved, so a binary linked into ~/.local/bin
     * still names its real file.
     *
     * @return list<string> the standalone binary alone, or PHP followed by the PHAR or the
     *                      source checkout's entry script
     */
    public static function command(): array
    {
        return self::forRuntime(PHP_BINARY, Phar::running(false));
    }

    /**
     * command() for a given PHP_BINARY and Phar::running(false), so tests can try the PHAR and
     * the standalone build.
     *
     * @param  string  $phar  empty when running from source
     * @return list<string>
     */
    public static function forRuntime(string $php, string $phar): array
    {
        // realpath('') is the working directory, so an empty PHP_BINARY has to stay empty.
        $php = $php === '' ? '' : (realpath($php) ?: $php);

        if ($phar === '') {
            $script = dirname(__DIR__, 2).'/dotsnitch';

            return [$php, realpath($script) ?: $script];
        }

        $phar = realpath($phar) ?: $phar;

        // The standalone build is the PHAR joined onto PHP. Its PHP_BINARY is empty, or names
        // the same file on runtimes that fill it in.
        return in_array($php, ['', $phar], true) ? [$phar] : [$php, $phar];
    }
}
