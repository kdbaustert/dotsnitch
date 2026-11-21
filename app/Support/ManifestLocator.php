<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\ManifestMatches;
use Symfony\Component\Finder\Finder;

/**
 * Finds manifests anywhere in the dotfiles repo by file name, never by path.
 */
final class ManifestLocator
{
    private const MAX_DEPTH = 6;

    private const SKIPPED_DIRECTORIES = ['.git', 'node_modules', 'vendor', '.cache'];

    /** Suffixes that mark a tool's or editor's side file, not a machine variant. */
    private const IGNORED_SUFFIXES = ['lock.json', 'bak', 'orig'];

    /**
     * @param  string  $hostname  a file suffixed with this is a host overlay; any other suffix is a variant
     */
    public function __construct(private readonly string $hostname) {}

    /**
     * @param  array<string, list<string>>  $patternsByCheck
     * @return array<string, ManifestMatches> keyed like $patternsByCheck
     */
    public function locate(string $repo, array $patternsByCheck): array
    {
        $files = $this->filesIn($repo);
        $matches = [];

        foreach ($patternsByCheck as $check => $patterns) {
            $matches[$check] = $this->matchesFor($files, $patterns);
        }

        return $matches;
    }

    /**
     * @return list<string> relative paths, sorted
     */
    private function filesIn(string $repo): array
    {
        $finder = Finder::create()
            ->files()
            ->in($repo)
            ->depth('< '.self::MAX_DEPTH)
            ->exclude(self::SKIPPED_DIRECTORIES)
            ->ignoreDotFiles(false)
            ->ignoreVCS(true)
            ->followLinks()
            ->sortByName();

        $files = [];

        foreach ($finder as $file) {
            $files[] = $file->getRelativePathname();
        }

        return $files;
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $patterns
     */
    private function matchesFor(array $files, array $patterns): ManifestMatches
    {
        $roles = ['base' => [], 'hostOverlay' => [], 'variant' => []];

        foreach ($files as $file) {
            foreach ($patterns as $pattern) {
                $role = $this->roleOf($file, $pattern);

                if ($role !== null) {
                    $roles[$role][] = $file;

                    break;
                }
            }
        }

        return new ManifestMatches($roles['base'], $roles['hostOverlay'], $roles['variant']);
    }

    /**
     * @return ?string "base", "hostOverlay" or "variant"; null when the file doesn't match
     */
    private function roleOf(string $file, string $pattern): ?string
    {
        [$parent, $name] = str_contains($pattern, '/') ? explode('/', $pattern, 2) : [null, $pattern];
        $fileName = basename($file);

        if ($parent !== null && basename(dirname($file)) !== $parent) {
            return null;
        }

        if ($this->isGlob($name)) {
            return fnmatch($name, $fileName) ? 'base' : null;
        }

        if ($fileName === $name) {
            return 'base';
        }

        if (! str_starts_with($fileName, "{$name}.")) {
            return null;
        }

        $suffix = substr($fileName, strlen($name) + 1);

        if (in_array($suffix, self::IGNORED_SUFFIXES, true)) {
            return null;
        }

        // macOS file names are case-insensitive by default, so Brewfile.kennys-macbook-pro counts too.
        return strcasecmp($suffix, $this->hostname) === 0 ? 'hostOverlay' : 'variant';
    }

    /**
     * A name with *, ? or [ goes through fnmatch() instead of an exact compare.
     */
    private function isGlob(string $name): bool
    {
        return strpbrk($name, '*?[') !== false;
    }
}
