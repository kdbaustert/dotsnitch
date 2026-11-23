<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ListsOutdated;
use App\Contracts\ManifestFormat;
use App\Contracts\SharesManifest;
use App\Contracts\Shell;
use App\Data\Package;
use App\Manifests\BrewfileLineFormat;
use App\Manifests\PythonSpec;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Python tools installed with `uv tool install`.
 */
final class Uv extends PackageManagerTarget implements ListsOutdated, SharesManifest
{
    /** A tool line, "ruff v0.6.9", as opposed to the "- ruff" entry point lines under it. */
    private const TOOL_LINE = '/^(\S+) v(\S+)/';

    /** The same line from `uv tool list --outdated`: "ruff v0.6.9 [latest: 0.8.0]". */
    private const OUTDATED_LINE = '/^(\S+) v(\S+).*\[latest: ([^\]]+)\]/';

    private readonly PythonSpec $spec;

    /**
     * The same PythonSpec pipx uses, since `uv tool install` takes the same requirements.
     */
    public function __construct(Shell $shell, PathResolver $paths, ManifestEditor $editor)
    {
        parent::__construct($shell, $paths, $editor);
        $this->spec = new PythonSpec;
    }

    /**
     * Just uv, where the manifests are named uv-tools.
     */
    public function key(): string
    {
        return 'uv';
    }

    /**
     * Lowercase like uv itself. Says tools, since only `uv tool install` packages are checked.
     */
    public function label(): string
    {
        return 'uv tools';
    }

    /**
     * Finds uv in ~/.local/bin, where its installer puts it, even when the login PATH leaves
     * that out.
     */
    public function isAvailable(): bool
    {
        return $this->paths->find('uv') !== null;
    }

    /**
     * A plain list, a uv-tools.sh whose bash array holds the names, or the uv lines of a Brewfile.
     */
    public function manifestPatterns(): array
    {
        return ['uv-tools.txt', 'uv-tools.sh', 'Brewfile', '.Brewfile'];
    }

    /**
     * uv-tools.sh or the plain list. Entries are requirements such as "ruff>=0.6", as for pipx.
     * A Brewfile's uv lines are read by name only.
     */
    protected function format(string $manifest): ManifestFormat
    {
        return BrewfileLineFormat::isBrewfile($manifest) ? new BrewfileLineFormat('uv') : $this->scriptOrList($manifest, $this->spec);
    }

    /**
     * uv tool list has no JSON output, so this reads its text. With nothing installed it says so
     * on stderr and prints nothing.
     *
     * @throws RuntimeException when uv tool list fails
     */
    protected function installed(): Collection
    {
        return collect($this->lines([$this->binary('uv'), 'tool', 'list']))
            ->map(fn (string $line) => preg_match(self::TOOL_LINE, $line, $match) ? new Package(PythonSpec::normalized($match[1]), 'package', $match[2]) : null)
            ->filter()
            ->values();
    }

    /**
     * @throws RuntimeException when uv tool list fails
     */
    public function outdated(): array
    {
        $outdated = [];

        foreach ($this->lines([$this->binary('uv'), 'tool', 'list', '--outdated']) as $line) {
            if (preg_match(self::OUTDATED_LINE, $line, $match)) {
                $outdated[] = ['package' => new Package(PythonSpec::normalized($match[1]), 'package', $match[2]), 'latest' => $match[3]];
            }
        }

        return $outdated;
    }

    /**
     * Keeps the manifest's extras and specifier.
     */
    protected function installCommand(Package $package): array
    {
        return [$this->binary('uv'), 'tool', 'install', $this->spec->argument($package)];
    }

    protected function pinAllows(string $pin, string $version): bool
    {
        return PythonSpec::allows($pin, $version);
    }

    /**
     * By name only.
     */
    protected function uninstallCommand(Package $package): array
    {
        return [$this->binary('uv'), 'tool', 'uninstall', $package->name];
    }
}
