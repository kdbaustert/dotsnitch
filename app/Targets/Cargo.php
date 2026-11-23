<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ListsOutdated;
use App\Contracts\ManifestFormat;
use App\Contracts\SharesManifest;
use App\Contracts\Shell;
use App\Data\Package;
use App\Manifests\BrewfileLineFormat;
use App\Manifests\CargoSpec;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Binaries installed with `cargo install`.
 */
final class Cargo extends PackageManagerTarget implements ListsOutdated, SharesManifest
{
    /** "ripgrep v14.1.1:", with " (source)" before the colon when it didn't come from crates.io. */
    private const CRATE_LINE = '/^(\S+) v(\S+?)(?: \(.+\))?:$/';

    /** The same line for a crate that did come from crates.io. */
    private const REGISTRY_CRATE_LINE = '/^(\S+) v(\S+):$/';

    /** cargo search's line for a crate: ripgrep = "14.1.1", then a comment. */
    private const SEARCH_LINE = '/^(\S+) = "([^"]+)"/';

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
     * Plain lists, a cargo.sh whose bash array holds the names, or the cargo lines of a Brewfile.
     */
    public function manifestPatterns(): array
    {
        return ['cargo.txt', 'Cargofile', 'cargo.sh', 'Brewfile', '.Brewfile'];
    }

    /**
     * cargo.sh and a plain list hold crates, pinned as name@version if at all. A Brewfile's cargo
     * lines never pin.
     */
    protected function format(string $manifest): ManifestFormat
    {
        return BrewfileLineFormat::isBrewfile($manifest) ? new BrewfileLineFormat('cargo') : $this->scriptOrList($manifest, $this->spec);
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
     * Cargo has no outdated command for installed binaries, so this searches crates.io once per
     * crate. A crate installed from git or a path isn't looked up, since crates.io's version
     * says nothing about it.
     *
     * @throws RuntimeException when cargo install --list or a search fails
     */
    public function outdated(): array
    {
        $outdated = [];

        foreach ($this->lines([$this->binary('cargo'), 'install', '--list']) as $line) {
            if (! preg_match(self::REGISTRY_CRATE_LINE, $line, $crate)) {
                continue;
            }

            foreach ($this->lines([$this->binary('cargo'), 'search', '--limit', '1', $crate[1]]) as $found) {
                if (preg_match(self::SEARCH_LINE, $found, $match) && $match[1] === $crate[1] && version_compare($match[2], $crate[2], '>')) {
                    $outdated[] = ['package' => new Package($crate[1], 'crate', $crate[2]), 'latest' => $match[2]];
                }
            }
        }

        return $outdated;
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
