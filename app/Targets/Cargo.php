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
     * Keeps one CargoSpec for reading manifests and for the `cargo install` argument.
     */
    public function __construct(Shell $shell, PathResolver $paths, ManifestEditor $editor)
    {
        parent::__construct($shell, $paths, $editor);
        $this->spec = new CargoSpec;
    }

    /**
     * The same word as the binary and as cargo.txt and cargo.sh, two of its manifests.
     */
    public function key(): string
    {
        return 'cargo';
    }

    /**
     * Capitalized, the way Rust's docs write the tool's name.
     */
    public function label(): string
    {
        return 'Cargo';
    }

    /**
     * Finds a rustup install too. $CARGO_HOME/bin, or ~/.cargo/bin, is searched even when the
     * login PATH leaves it out.
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
     * cargo.sh or a plain list. Both hold crates, pinned as name@version if at all.
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
        return collect($this->lines([$this->binary('cargo'), 'install', '--list']))
            ->map(fn (string $line) => preg_match(self::CRATE_LINE, $line, $match) ? new Package($match[1], 'crate', $match[2]) : null)
            ->filter()
            ->values();
    }

    /**
     * Keeps the manifest's pin, as name@version.
     */
    protected function installCommand(Package $package): array
    {
        return [$this->binary('cargo'), 'install', $this->spec->argument($package)];
    }

    /**
     * By name only.
     */
    protected function uninstallCommand(Package $package): array
    {
        return [$this->binary('cargo'), 'uninstall', $package->name];
    }
}
