<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ManifestFormat;
use App\Contracts\Shell;
use App\Data\Package;
use App\Manifests\CargoSpec;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Binaries installed with `cargo install`.
 */
final class Cargo extends PackageManagerTarget
{
    /** "ripgrep v14.1.1:", with " (source)" before the colon when it didn't come from crates.io. */
    private const CRATE_LINE = '/^(\S+) v(\S+?)(?: \(.+\))?:$/';

    private readonly CargoSpec $spec;

    /**
     * The resolver finds cargo.
     */
    public function __construct(
        Shell $shell,
        private readonly PathResolver $paths,
        ManifestEditor $editor,
    ) {
        parent::__construct($shell, $editor);
        $this->spec = new CargoSpec;
    }

    /**
     * Used in --checks and under ignore.cargo in config.json.
     */
    public function key(): string
    {
        return 'cargo';
    }

    /**
     * Shown in the report.
     */
    public function label(): string
    {
        return 'Cargo';
    }

    /**
     * True when cargo is on the resolved PATH.
     */
    public function isAvailable(): bool
    {
        return $this->paths->find('cargo') !== null;
    }

    /**
     * Plain lists, or a cargo.sh whose bash array holds the names.
     */
    public function manifestPatterns(): array
    {
        return ['cargo.txt', 'Cargofile', 'cargo.sh'];
    }

    /**
     * A .sh reads its bash arrays; anything else is a list of crates.
     */
    protected function format(string $manifest): ManifestFormat
    {
        return $this->scriptOrList($manifest, $this->spec);
    }

    /**
     * Reads the crate lines of cargo install --list and skips the indented binary lines under them.
     *
     * @throws RuntimeException when cargo install --list fails
     */
    protected function installed(): Collection
    {
        return collect($this->lines([$this->cargo(), 'install', '--list']))
            ->map(fn (string $line) => preg_match(self::CRATE_LINE, $line, $match) ? new Package($match[1], 'crate', $match[2]) : null)
            ->filter()
            ->values();
    }

    /**
     * Keeps the manifest's pin, as name@version.
     */
    protected function installCommand(Package $package): array
    {
        return [$this->cargo(), 'install', $this->spec->argument($package)];
    }

    /**
     * By name only.
     */
    protected function uninstallCommand(Package $package): array
    {
        return [$this->cargo(), 'uninstall', $package->name];
    }

    /**
     * @throws RuntimeException when cargo isn't on PATH
     */
    private function cargo(): string
    {
        return $this->paths->find('cargo') ?? throw new RuntimeException('cargo is not installed');
    }
}
