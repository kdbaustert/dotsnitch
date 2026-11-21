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
     * The resolver finds uv.
     */
    public function __construct(
        Shell $shell,
        private readonly PathResolver $paths,
        ManifestEditor $editor,
    ) {
        parent::__construct($shell, $editor);
        $this->spec = new PythonSpec;
    }

    /**
     * Used in --checks and under ignore.uv in config.json.
     */
    public function key(): string
    {
        return 'uv';
    }

    /**
     * Shown in the report.
     */
    public function label(): string
    {
        return 'uv tools';
    }

    /**
     * True when uv is on the resolved PATH.
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
     * A .sh reads its bash arrays; anything else is a list of requirements.
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
        return collect($this->lines([$this->uv(), 'tool', 'list']))
            ->map(fn (string $line) => preg_match(self::TOOL_LINE, $line, $match) ? new Package(PythonSpec::normalized($match[1]), 'package', $match[2]) : null)
            ->filter()
            ->values();
    }

    /**
     * Keeps the manifest's extras and specifier.
     */
    protected function installCommand(Package $package): array
    {
        return [$this->uv(), 'tool', 'install', $this->spec->argument($package)];
    }

    /**
     * By name only.
     */
    protected function uninstallCommand(Package $package): array
    {
        return [$this->uv(), 'tool', 'uninstall', $package->name];
    }

    /**
     * @throws RuntimeException when uv isn't on PATH
     */
    private function uv(): string
    {
        return $this->paths->find('uv') ?? throw new RuntimeException('uv is not installed');
    }
}
