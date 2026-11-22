<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Prompts\Exceptions\NonInteractiveValidationException;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

/**
 * Works out which dotfiles directory to use. First hit wins: --repo=, DOTSNITCH_REPO, the saved
 * path, the git repo your home symlinks point into, then asking.
 */
final class DotfilesRepo
{
    /** What resolve() returned this run, for checks that read the whole repo. */
    private ?string $root = null;

    /**
     * @param  array<string, string>  $inherited  the environment this process started with
     */
    public function __construct(
        private readonly UserSettings $settings,
        private readonly array $inherited,
    ) {}

    /**
     * Remembers the answer for root().
     *
     * @param  ?string  $option  the --repo= value, null when not passed
     * @return string absolute, symlinks resolved
     *
     * @throws RuntimeException when --repo= or DOTSNITCH_REPO names a missing directory, or none is found
     */
    public function resolve(?string $option): string
    {
        return $this->root = $this->locate($option);
    }

    /**
     * The directory this run uses.
     *
     * @throws RuntimeException when resolve() hasn't run yet
     */
    public function root(): string
    {
        return $this->root ?? throw new RuntimeException('The dotfiles directory isn\'t resolved yet.');
    }

    /**
     * Walks the order in the class comment. A --repo= or DOTSNITCH_REPO that doesn't exist is an
     * error, while a saved path that's gone just falls through to the next step.
     */
    private function locate(?string $option): string
    {
        if ($option !== null && $option !== '') {
            return $this->fromOption($option);
        }

        $fromEnvironment = $this->inherited['DOTSNITCH_REPO'] ?? '';

        if ($fromEnvironment !== '') {
            return $this->existingDirectory($fromEnvironment, 'DOTSNITCH_REPO');
        }

        $saved = $this->settings->get('repo');
        $savedDirectory = is_string($saved) ? $this->directoryOrNull($saved) : null;

        if ($savedDirectory !== null) {
            return $savedDirectory;
        }

        return $this->linkedRepo() ?? $this->askForDirectory();
    }

    /**
     * The git repo that most symlinks in ~ and the XDG config dir point into, since that's
     * where a dotfiles setup links from.
     *
     * @return ?string null when no symlink there leads into a git repo
     */
    private function linkedRepo(): ?string
    {
        $home = $this->inherited['HOME'] ?? '';
        // Set but empty counts as unset, as the XDG spec has it.
        $configHome = ($this->inherited['XDG_CONFIG_HOME'] ?? '') ?: "{$home}/.config";
        $links = [];

        foreach ([$home, $configHome] as $dir) {
            // A fresh Mac may have no ~/.config yet.
            foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
                if (is_link("{$dir}/{$name}")) {
                    $links[] = "{$dir}/{$name}";
                }
            }
        }

        $roots = array_filter(array_map(fn (string $link) => $this->gitRootOf($link), $links));

        if ($roots === []) {
            return null;
        }

        $counts = array_count_values($roots);
        arsort($counts);

        return array_key_first($counts);
    }

    /**
     * @param  string  $link  a symlink; broken ones have no repo
     * @return ?string the nearest directory above its target that holds .git
     */
    private function gitRootOf(string $link): ?string
    {
        $target = realpath($link);

        if ($target === false) {
            return null;
        }

        for ($dir = is_dir($target) ? $target : dirname($target); $dir !== '/'; $dir = dirname($dir)) {
            if (file_exists("{$dir}/.git")) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * A --repo= value applies to this run; saving it as the default is the user's call.
     */
    private function fromOption(string $option): string
    {
        $repo = $this->existingDirectory($option, '--repo=');
        $saved = $this->settings->get('repo');

        if ($saved !== $repo && confirm("Save {$repo} as the dotfiles directory for this Mac?", default: false)) {
            $this->settings->set('repo', $repo);
        }

        return $repo;
    }

    /**
     * Keeps asking until the answer is a directory, then offers to save it.
     *
     * @throws RuntimeException when there's no terminal to ask in
     */
    private function askForDirectory(): string
    {
        try {
            $answer = text(
                'Where is your dotfiles directory?',
                required: true,
                validate: fn (string $value) => $this->directoryOrNull($value) === null ? 'No directory there.' : null,
            );
        } catch (NonInteractiveValidationException) {
            throw new RuntimeException("Couldn't find your dotfiles directory. Pass --repo= or set DOTSNITCH_REPO.");
        }

        $repo = $this->directoryOrNull($answer);

        if (confirm("Save {$repo} as the dotfiles directory for this Mac?")) {
            $this->settings->set('repo', $repo);
        }

        return $repo;
    }

    /**
     * @param  string  $source  where the path came from, such as "--repo=", for the error
     *
     * @throws RuntimeException when $path isn't a directory
     */
    private function existingDirectory(string $path, string $source): string
    {
        return $this->directoryOrNull($path)
            ?? throw new RuntimeException("{$source} points at {$path}, which isn't a directory.");
    }

    /**
     * Expands a leading ~ and resolves relative paths against the current directory.
     */
    private function directoryOrNull(string $path): ?string
    {
        $home = $this->inherited['HOME'] ?? '';
        $expanded = preg_replace('#^~(?=/|$)#', $home, $path);
        $real = realpath($expanded);

        return $real !== false && is_dir($real) ? $real : null;
    }
}
