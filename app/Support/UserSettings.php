<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Per-Mac settings (repo path, ignores, saved choices). Lives outside the dotfiles repo so it's never committed.
 */
final class UserSettings
{
    /** @var array<string, mixed> */
    private array $settings;

    /**
     * @throws RuntimeException when the file exists but isn't valid JSON
     */
    public function __construct(private readonly string $path)
    {
        $this->settings = JsonFile::read($path) ?? [];
    }

    /**
     * @param  string  $key  dot notation, such as "ignore.brew"
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings, $key, $default);
    }

    /**
     * Saves to disk right away, creating the file on first use.
     */
    public function set(string $key, mixed $value): void
    {
        Arr::set($this->settings, $key, $value);
        JsonFile::write($this->path, $this->settings);
    }
}
