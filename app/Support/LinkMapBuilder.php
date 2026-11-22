<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\Link;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Works out which symlinks the dotfiles repo expects. Dotbot configs, Stow packages and *.symlink
 * files say so directly, in that order, and the first one to claim a path wins. Every other link
 * that already points into the repo counts too, as does one that points at the same path in some
 * other folder, such as an old checkout.
 */
final class LinkMapBuilder
{
    /** Names Stow skips anywhere in a package, plus Finder's litter. */
    private const STOW_IGNORED = ['.git', '.gitignore', '.gitmodules', '.DS_Store'];

    /** File names Stow skips at the top of a package. */
    private const STOW_IGNORED_AT_TOP = '/^(README|LICENSE|COPYING)/';

    /** Stow's --dotfiles prefix, which becomes a leading dot. */
    private const STOW_DOT_PREFIX = 'dot-';

    /**
     * @param  string  $root  the dotfiles repo, symlinks resolved
     * @param  string  $configHome  XDG_CONFIG_HOME, or ~/.config
     */
    public function __construct(
        private readonly string $root,
        private readonly string $home,
        private readonly string $configHome,
    ) {}

    /**
     * @param  list<string>  $manifests  absolute paths to the Dotbot configs, .stowrc and *.symlink files
     * @param  callable(string): void  $log  takes one status line, such as why an entry was left out
     * @return array<string, Link> keyed by target
     */
    public function build(array $manifests, callable $log): array
    {
        $links = [];

        foreach ($this->declared($manifests, $log) as $link) {
            if (! file_exists($link->source) && ! is_link($link->source)) {
                $log("{$link->source} doesn't exist, so {$link->target} was left out");

                continue;
            }

            $claimed = $links[$link->target] ?? null;

            if ($claimed !== null && $claimed->source !== $link->source) {
                $log("{$link->target}: kept {$claimed->source}, not {$link->source}");

                continue;
            }

            $links[$link->target] = $link;
        }

        foreach ($this->existingLinks() as $link) {
            $links[$link->target] ??= $link;
        }

        return $links;
    }

    /**
     * What the layout files ask for: Dotbot first, then Stow, then *.symlink. A repo with a Dotbot
     * config only gets Stow packages when it also has a .stowrc, since Dotbot's own submodule and
     * folders like bin/ would look like packages.
     *
     * @param  list<string>  $manifests
     * @param  callable(string): void  $log
     * @return list<Link>
     */
    private function declared(array $manifests, callable $log): array
    {
        $usesDotbot = false;
        $dotbot = [];
        $symlinkFiles = [];

        foreach ($manifests as $manifest) {
            if (str_ends_with($manifest, '.conf.yaml')) {
                $usesDotbot = true;
                array_push($dotbot, ...$this->fromDotbot($manifest, $log));
            } elseif (str_ends_with($manifest, '.symlink')) {
                $symlinkFiles[] = $this->fromSymlinkFile($manifest);
            }
        }

        $stow = $usesDotbot && ! is_file("{$this->root}/.stowrc") ? [] : $this->fromStow();

        return [...$dotbot, ...$stow, ...$symlinkFiles];
    }

    /**
     * Reads the link directives, with any defaults.link options merged in. Entries with an
     * "if:" are left out, since that runs a shell command.
     *
     * @param  callable(string): void  $log
     * @return list<Link>
     */
    private function fromDotbot(string $config, callable $log): array
    {
        try {
            $directives = Yaml::parseFile($config);
        } catch (ParseException $e) {
            $log(basename($config)." isn't valid YAML: {$e->getMessage()}");

            return [];
        }

        $base = dirname($config);
        $defaults = [];
        $links = [];

        foreach (is_array($directives) ? $directives : [] as $directive) {
            if (! is_array($directive)) {
                continue;
            }

            $defaults = array_merge($defaults, (array) ($directive['defaults']['link'] ?? []));

            foreach ((array) ($directive['link'] ?? []) as $target => $entry) {
                $options = array_merge($defaults, is_array($entry) ? $entry : ['path' => $entry]);

                if (isset($options['if'])) {
                    $log("Skipped {$target} in ".basename($config).', it has an if: condition');

                    continue;
                }

                $target = $this->expand((string) $target, $base);
                array_push($links, ...$this->dotbotLinks($options, $target, $base));
            }
        }

        return $links;
    }

    /**
     * One link, or one per match when glob is on. With no path, Dotbot uses the target's name
     * without its leading dot.
     *
     * @param  array<string, mixed>  $options  path, glob and prefix
     * @param  string  $target  absolute
     * @param  string  $base  the folder the config is in, which relative paths start from
     * @return list<Link>
     */
    private function dotbotLinks(array $options, string $target, string $base): array
    {
        $path = $options['path'] ?? null;
        $path = is_string($path) ? $path : ltrim(basename($target), '.');

        if (! ($options['glob'] ?? false)) {
            return [new Link($this->expand($path, $base), $target)];
        }

        $prefix = (string) ($options['prefix'] ?? '');

        return array_map(
            fn (string $match) => new Link($match, "{$target}/{$prefix}".basename($match)),
            glob($this->expand($path, $base)) ?: [],
        );
    }

    /**
     * Each Stow package's files, linked one by one. A package is a top-level folder holding
     * something that starts with a dot, or with "dot-" when .stowrc has --dotfiles.
     *
     * @return list<Link>
     */
    private function fromStow(): array
    {
        [$target, $dotfiles] = $this->stowOptions();
        $links = [];

        foreach (scandir($this->root) ?: [] as $name) {
            $package = "{$this->root}/{$name}";

            if (str_starts_with($name, '.') || is_link($package) || ! is_dir($package) || ! $this->isStowPackage($package, $dotfiles)) {
                continue;
            }

            foreach ($this->filesIn($package) as $relative) {
                $links[] = new Link("{$package}/{$relative}", $target.'/'.$this->stowName($relative, $dotfiles));
            }
        }

        return $links;
    }

    /**
     * Reads --target and --dotfiles from the repo's .stowrc. Without a target, Stow links into
     * the folder above the repo.
     *
     * @return array{string, bool} the target folder, and whether dot- means a dot
     */
    private function stowOptions(): array
    {
        $stowrc = "{$this->root}/.stowrc";
        $words = is_readable($stowrc) ? preg_split('/\s+/', (string) file_get_contents($stowrc), -1, PREG_SPLIT_NO_EMPTY) ?: [] : [];
        $target = dirname($this->root);

        foreach ($words as $index => $word) {
            if (str_starts_with($word, '--target=')) {
                $target = $this->expand(substr($word, strlen('--target=')), $this->root);
            } elseif ($word === '-t' && isset($words[$index + 1])) {
                $target = $this->expand($words[$index + 1], $this->root);
            }
        }

        return [$target, in_array('--dotfiles', $words, true)];
    }

    /**
     * Holds an entry that would become a dotfile in the target. A submodule or nested repo never
     * counts, since it's someone else's project.
     */
    private function isStowPackage(string $folder, bool $dotfiles): bool
    {
        if (file_exists("{$folder}/.git")) {
            return false;
        }

        foreach (scandir($folder) ?: [] as $name) {
            $isDotEntry = str_starts_with($name, '.') && ! in_array($name, ['.', '..', ...self::STOW_IGNORED], true);

            if ($isDotEntry || ($dotfiles && str_starts_with($name, self::STOW_DOT_PREFIX))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every file under a Stow package, minus what Stow ignores.
     *
     * @return list<string> relative to $package
     */
    private function filesIn(string $package, string $relative = ''): array
    {
        $files = [];

        foreach (scandir($relative === '' ? $package : "{$package}/{$relative}") ?: [] as $name) {
            $skippedAtTop = $relative === '' && preg_match(self::STOW_IGNORED_AT_TOP, $name);

            if (in_array($name, ['.', '..', ...self::STOW_IGNORED], true) || $skippedAtTop) {
                continue;
            }

            $path = $relative === '' ? $name : "{$relative}/{$name}";
            $isFolder = is_dir("{$package}/{$path}") && ! is_link("{$package}/{$path}");
            array_push($files, ...($isFolder ? $this->filesIn($package, $path) : [$path]));
        }

        return $files;
    }

    /**
     * With --dotfiles, each "dot-" at the start of a path part becomes a dot.
     */
    private function stowName(string $relative, bool $dotfiles): string
    {
        if (! $dotfiles) {
            return $relative;
        }

        return implode('/', array_map(
            fn (string $part) => str_starts_with($part, self::STOW_DOT_PREFIX) ? '.'.substr($part, strlen(self::STOW_DOT_PREFIX)) : $part,
            explode('/', $relative),
        ));
    }

    /**
     * zsh/zshrc.symlink becomes ~/.zshrc.
     */
    private function fromSymlinkFile(string $file): Link
    {
        return new Link($file, "{$this->home}/.".basename($file, '.symlink'));
    }

    /**
     * Links in ~ and the config folder that point into the repo, or at the same path somewhere
     * else.
     *
     * @return list<Link>
     */
    private function existingLinks(): array
    {
        $paths = array_unique([...$this->linksUnder($this->home, 1), ...$this->linksUnder($this->configHome, 2)]);

        return array_values(array_filter(array_map($this->linkFor(...), $paths)));
    }

    /**
     * Symlinks in $dir and $depth - 1 levels below it. Folders the repo also has keep going past
     * that, so repo/.vim/pack means ~/.vim/pack is read too.
     *
     * @return list<string>
     */
    private function linksUnder(string $dir, int $depth): array
    {
        if ($dir === $this->root || ! is_dir($dir) || ! is_readable($dir)) {
            return [];
        }

        $found = [];

        foreach (scandir($dir) ?: [] as $name) {
            $path = "{$dir}/{$name}";

            if ($name === '.' || $name === '..') {
                continue;
            }

            if (is_link($path)) {
                $found[] = $path;
            } elseif (is_dir($path) && ($depth > 1 || $this->isMirrored($path))) {
                array_push($found, ...$this->linksUnder($path, $depth - 1));
            }
        }

        return $found;
    }

    /**
     * The repo has a real folder at the same place. At the top of ~ only dot-folders count, and
     * .git never does.
     */
    private function isMirrored(string $path): bool
    {
        $relative = $this->relativeToHome($path);

        if ($relative === null || $relative === '.git' || str_starts_with($relative, '.git/')) {
            return false;
        }

        if (! str_contains($relative, '/') && ! str_starts_with($relative, '.')) {
            return false;
        }

        $mirror = "{$this->root}/{$relative}";

        return is_dir($mirror) && ! is_link($mirror);
    }

    /**
     * Reads one hop of the link, so a repo file that is itself a link still counts as the source.
     *
     * @return ?Link null when the link has nothing to do with the repo
     */
    private function linkFor(string $path): ?Link
    {
        $pointsAt = readlink($path);

        if ($pointsAt === false) {
            return null;
        }

        $absolute = str_starts_with($pointsAt, '/') ? $pointsAt : dirname($path)."/{$pointsAt}";
        $resolved = (realpath(dirname($absolute)) ?: dirname($absolute)).'/'.basename($absolute);

        if (str_starts_with($resolved, "{$this->root}/")) {
            return new Link($resolved, $path);
        }

        $relative = $this->relativeToHome($path);
        $sameInRepo = "{$this->root}/{$relative}";

        if ($relative !== null && str_ends_with($absolute, "/{$relative}") && file_exists($sameInRepo)) {
            return new Link($sameInRepo, $path);
        }

        return null;
    }

    /**
     * @return ?string null when $path isn't under ~
     */
    private function relativeToHome(string $path): ?string
    {
        return str_starts_with($path, "{$this->home}/") ? substr($path, strlen($this->home) + 1) : null;
    }

    /**
     * Expands ~, $HOME and ${HOME}, makes a relative path start from $base, and drops a
     * trailing slash.
     */
    private function expand(string $path, string $base): string
    {
        $path = preg_replace(['#^~(?=/|$)#', '#\$\{HOME\}|\$HOME\b#'], $this->home, $path);
        $path = str_starts_with($path, '/') ? $path : "{$base}/{$path}";

        return $path === '/' ? $path : rtrim($path, '/');
    }
}
