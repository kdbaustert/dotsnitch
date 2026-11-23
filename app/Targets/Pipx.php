<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ListsOutdated;
use App\Contracts\ManifestFormat;
use App\Contracts\Shell;
use App\Data\Package;
use App\Manifests\PythonSpec;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Python apps installed with pipx. Packages injected into an app's venv aren't counted.
 */
final class Pipx extends PackageManagerTarget implements ListsOutdated
{
    private readonly PythonSpec $spec;

    /** @var array<string, string> venv name by package, from the last installed(); a venv can carry a --suffix */
    private array $venvs = [];

    /**
     * PythonSpec is shared with Uv, since both take pip-style requirements.
     */
    public function __construct(Shell $shell, PathResolver $paths, ManifestEditor $editor)
    {
        parent::__construct($shell, $paths, $editor);
        $this->spec = new PythonSpec;
    }

    /**
     * Not shared with uv, though both install the same kind of tool.
     */
    public function key(): string
    {
        return 'pipx';
    }

    /**
     * Lowercase, the way pipx writes its own name.
     */
    public function label(): string
    {
        return 'pipx';
    }

    /**
     * True when pipx is on the resolved PATH.
     */
    public function isAvailable(): bool
    {
        return $this->paths->find('pipx') !== null;
    }

    /**
     * Plain lists, or a pipx.sh whose bash array holds the names.
     */
    public function manifestPatterns(): array
    {
        return ['pipx.txt', 'Pipxfile', 'pipx.sh'];
    }

    /**
     * pipx.sh or a plain list. Both hold pip-style requirements such as black[d].
     */
    protected function format(string $manifest): ManifestFormat
    {
        return $this->scriptOrList($manifest, $this->spec);
    }

    /**
     * Reads each venv's main package from pipx list --json.
     *
     * @throws RuntimeException when pipx prints no JSON
     */
    protected function installed(): Collection
    {
        $packages = collect();
        $this->venvs = [];

        foreach ($this->json([$this->binary('pipx'), 'list', '--json'])['venvs'] ?? [] as $venv => $data) {
            $main = $data['metadata']['main_package'] ?? [];
            $name = PythonSpec::normalized((string) ($main['package'] ?? $venv));
            $this->venvs[$name] = (string) $venv;
            $packages->push(new Package($name, 'package', isset($main['package_version']) ? (string) $main['package_version'] : null));
        }

        return $packages;
    }

    /**
     * Needs a pipx that has `list --outdated`. An older one fails here with its usage text.
     * Packages injected into an app's venv are left out, as they are from the check.
     *
     * @throws RuntimeException when pipx prints no JSON
     */
    public function outdated(): array
    {
        $listed = $this->json([$this->binary('pipx'), 'list', '--outdated', '--output', 'json'])['data']['packages'] ?? [];

        return array_values(array_map(
            fn (array $entry) => ['package' => new Package(PythonSpec::normalized($entry['package']), 'package', $entry['version']), 'latest' => $entry['latest_version']],
            array_filter($listed, fn (array $entry) => ! ($entry['injected'] ?? false)),
        ));
    }

    /**
     * Keeps the manifest's extras and specifier.
     */
    protected function installCommand(Package $package): array
    {
        return [$this->binary('pipx'), 'install', $this->spec->argument($package)];
    }

    protected function pinAllows(string $pin, string $version): bool
    {
        return PythonSpec::allows($pin, $version);
    }

    /**
     * pipx leaves an installed package alone without --force.
     */
    protected function reinstallCommands(Package $package): array
    {
        return [[$this->binary('pipx'), 'install', '--force', $this->spec->argument($package)]];
    }

    /**
     * pipx removes by venv name, which differs from the package when it was installed with --suffix.
     */
    protected function uninstallCommand(Package $package): array
    {
        return [$this->binary('pipx'), 'uninstall', $this->venvs[$package->name] ?? $package->name];
    }
}
