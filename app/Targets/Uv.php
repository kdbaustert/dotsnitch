<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ManifestFormat;
use App\Contracts\Shell;
use App\Data\Package;
use App\Manifests\PythonSpec;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Python tools installed with `uv tool install`.
 */
final class Uv extends PackageManagerTarget
{
    /** A tool line, "ruff v0.6.9", as opposed to the "- ruff" entry point lines under it. */
    private const TOOL_LINE = '/^(\S+) v(\S+)/';

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
     * A plain list, or a uv-tools.sh whose bash array holds the names.
     */
    public function manifestPatterns(): array
    {
        return ['uv-tools.txt', 'uv-tools.sh'];
    }

    /**
     * uv-tools.sh or the plain list. Entries are requirements such as "ruff>=0.6", as for pipx.
     */
    protected function format(string $manifest): ManifestFormat
    {
        return $this->scriptOrList($manifest, $this->spec);
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
