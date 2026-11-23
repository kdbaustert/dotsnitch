<?php

declare(strict_types=1);

namespace App\Data;

use RuntimeException;

/**
 * Every setting a bare `defaults read` printed, as text. It's only good for telling which
 * settings changed between two reads. The value to write comes from reading the one setting.
 */
final readonly class DefaultsSnapshot
{
    /** A domain or key as the dump prints it: quoted when it has to be, bare otherwise. */
    private const NAME = '("(?:[^"\\\\]|\\\\.)*"|[^\s"]\S*)';

    /**
     * @param  array<string, array<string, string>>  $settings  the printed value of each key, by domain
     */
    public function __construct(public array $settings) {}

    /**
     * Domains sit 4 spaces in and their keys 8. A value that spans lines, such as an array, keeps
     * every line under its key, so a change anywhere in it counts.
     */
    public static function parse(string $dump): self
    {
        $settings = [];
        $domain = null;
        $key = null;

        foreach (explode("\n", $dump) as $line) {
            if (preg_match('/^ {4}'.self::NAME.' = /', $line, $match)) {
                $domain = self::unquote($match[1]);
                $domain = $domain === 'Apple Global Domain' ? 'NSGlobalDomain' : $domain;
                $key = null;
            } elseif ($domain !== null && preg_match('/^ {8}'.self::NAME.' = \s*(.*)$/', $line, $match)) {
                $key = self::unquote($match[1]);
                $settings[$domain][$key] = $match[2];
            } elseif ($domain !== null && $key !== null && str_starts_with($line, str_repeat(' ', 8))) {
                $settings[$domain][$key] .= "\n".$line;
            } else {
                $key = null;
            }
        }

        return new self($settings);
    }

    /**
     * The settings as a saved baseline holds them.
     *
     * @param  array<mixed>  $data
     *
     * @throws RuntimeException when it isn't text by key by domain
     */
    public static function fromArray(array $data): self
    {
        foreach ($data as $keys) {
            if (! is_array($keys) || array_filter($keys, fn ($value) => ! is_string($value)) !== []) {
                throw new RuntimeException('Expected the printed value of each key, by domain');
            }
        }

        /** @var array<string, array<string, string>> $data */
        return new self($data);
    }

    /**
     * @param  list<string>  $domains
     */
    public function only(array $domains): self
    {
        return new self(array_intersect_key($this->settings, array_flip($domains)));
    }

    /**
     * @param  array<string, list<string>>  $keys  by domain
     */
    public function without(array $keys): self
    {
        $settings = $this->settings;

        foreach ($keys as $domain => $names) {
            if (isset($settings[$domain])) {
                $settings[$domain] = array_diff_key($settings[$domain], array_flip($names));
            }
        }

        return new self($settings);
    }

    /**
     * Settings that were deleted since aren't in it, since there's no line to write for those.
     *
     * @return list<array{string, string}> the domain and key of each setting that's new or prints differently
     */
    public function changedSince(self $before): array
    {
        $changed = [];

        foreach ($this->settings as $domain => $keys) {
            foreach ($keys as $key => $value) {
                if (($before->settings[$domain][$key] ?? null) !== $value) {
                    $changed[] = [(string) $domain, (string) $key];
                }
            }
        }

        return $changed;
    }

    private static function unquote(string $name): string
    {
        return str_starts_with($name, '"') ? stripcslashes(substr($name, 1, -1)) : $name;
    }
}
