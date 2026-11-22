<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ListsOutdated;
use App\Contracts\ManifestFormat;
use App\Contracts\Shell;
use App\Data\Package;
use App\Manifests\JsonRequireFormat;
use App\Manifests\LineListFormat;
use App\Manifests\NodeSpec;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Global npm packages, top level only.
 */
final class Npm extends PackageManagerTarget implements ListsOutdated
{
    private readonly NodeSpec $spec;

    /**
     * The resolver finds npm on your login PATH, which may not be the node you use interactively.
     */
    public function __construct(Shell $shell, PathResolver $paths, ManifestEditor $editor)
    {
        parent::__construct($shell, $paths, $editor);
        $this->spec = new NodeSpec;
    }

    /**
     * pnpm's globals are a separate check, so this key covers npm's only.
     */
    public function key(): string
    {
        return 'npm';
    }

    /**
     * Says global, since a project's own packages aren't checked.
     */
    public function label(): string
    {
        return 'npm (global)';
    }

    /**
     * Whichever npm the login PATH finds first, so the globals compared belong to that node.
     */
    public function isAvailable(): bool
    {
        return $this->paths->find('npm') !== null;
    }

    /**
     * A package.json only counts inside an npm/ folder, so a project's package.json isn't read.
     */
    public function manifestPatterns(): array
    {
        return ['npm-global.txt', '.npm-globals', 'npm/package.json'];
    }

    /**
     * A package.json reads its "dependencies"; anything else is a list of names.
     */
    protected function format(string $manifest): ManifestFormat
    {
        return $this->hasExtension($manifest, 'json')
            ? new JsonRequireFormat('dependencies', $this->spec)
            : new LineListFormat($this->spec);
    }

    /**
     * @throws RuntimeException when npm prints no JSON
     */
    protected function installed(): Collection
    {
        $tree = $this->json([$this->binary('npm'), 'ls', '-g', '--depth=0', '--json']);

        return collect($tree['dependencies'] ?? [])
            ->map(fn (array $package, string $name) => new Package($name, 'package', $package['version'] ?? null))
            ->values();
    }

    /**
     * @throws RuntimeException when npm prints no JSON
     */
    public function outdated(): array
    {
        $listed = $this->json([$this->binary('npm'), 'outdated', '-g', '--json']);

        return array_values(array_map(
            fn (array $entry, string $name) => ['package' => new Package($name, 'package', $entry['current'] ?? null), 'latest' => $entry['latest']],
            $listed,
            array_keys($listed),
        ));
    }

    /**
     * The npm running this check ships with node and updates itself, so it's never untracked.
     * It's found from where the npm binary really lives, such as lib/node_modules/npm/bin.
     */
    protected function trackedImplicitly(Collection $listed): array
    {
        $binary = realpath($this->binary('npm'));

        if ($binary === false || ! preg_match('~.*/node_modules/((?:@[^/]+/)?[^/]+)~', $binary, $match)) {
            return [];
        }

        return [(new Package($match[1], 'package'))->key()];
    }

    /**
     * Keeps the manifest's pin, so typescript@6 stays on 6.
     */
    protected function installCommand(Package $package): array
    {
        return [$this->binary('npm'), 'i', '-g', $this->spec->argument($package)];
    }

    /**
     * By name only, since a pin doesn't matter for a removal.
     */
    protected function uninstallCommand(Package $package): array
    {
        return [$this->binary('npm'), 'rm', '-g', $package->name];
    }
}
