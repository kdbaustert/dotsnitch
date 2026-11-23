<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ListsOutdated;
use App\Contracts\ManifestFormat;
use App\Contracts\Shell;
use App\Data\Package;
use App\Manifests\GhSpec;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * GitHub CLI extensions, by owner/repo.
 */
final class Gh extends PackageManagerTarget implements ListsOutdated
{
    /** A dry-run upgrade's line for an extension with a newer version, named without its gh- prefix. */
    private const UPGRADE_LINE = '/^\[(.+)\]: would have upgraded from (\S+) to (\S+)$/';

    private readonly GhSpec $spec;

    /**
     * Keeps one GhSpec. It leaves a pin out of the install argument, so installCommand() adds --pin.
     */
    public function __construct(Shell $shell, PathResolver $paths, ManifestEditor $editor)
    {
        parent::__construct($shell, $paths, $editor);
        $this->spec = new GhSpec;
    }

    /**
     * Just gh, where the manifests are named gh-extensions.
     */
    public function key(): string
    {
        return 'gh';
    }

    /**
     * Spelled out, since gh alone reads as the CLI and only its extensions are checked.
     */
    public function label(): string
    {
        return 'GitHub CLI extensions';
    }

    /**
     * True once gh is installed. Having no extensions yet is fine: the listed ones show as missing.
     */
    public function isAvailable(): bool
    {
        return $this->paths->find('gh') !== null;
    }

    /**
     * gh-extensions.sh is a setup script whose bash array holds the repos.
     */
    public function manifestPatterns(): array
    {
        return ['gh-extensions.txt', 'gh-extensions.sh'];
    }

    /**
     * gh-extensions.sh or the plain list. Both hold owner/repo, with @ref for a pin.
     */
    protected function format(string $manifest): ManifestFormat
    {
        return $this->scriptOrList($manifest, $this->spec);
    }

    /**
     * Without a terminal gh prints one tab-separated line per extension: "gh name", the repo,
     * then the version. A local extension has no repo, so it goes by its gh-name directory.
     *
     * @throws RuntimeException when gh extension list fails
     */
    protected function installed(): Collection
    {
        return collect($this->lines([$this->binary('gh'), 'extension', 'list']))->map(function (string $line) {
            [$command, $repo, $version] = array_pad(explode("\t", $line), 3, '');

            return new Package(
                $repo === '' ? str_replace(' ', '-', $command) : strtolower($repo),
                'extension',
                $version === '' ? null : $version,
            );
        });
    }

    /**
     * gh has no outdated command, so this reads a dry run of upgrading them all. A pinned or
     * local extension is never in it.
     *
     * @throws RuntimeException when either gh command fails
     */
    public function outdated(): array
    {
        $byDirectory = $this->installed()->keyBy(fn (Package $package) => basename($package->name));
        $outdated = [];

        foreach ($this->lines([$this->binary('gh'), 'extension', 'upgrade', '--all', '--dry-run']) as $line) {
            if (preg_match(self::UPGRADE_LINE, $line, $match)) {
                $name = $byDirectory->get("gh-{$match[1]}")->name ?? "gh-{$match[1]}";
                $outdated[] = ['package' => new Package($name, 'extension', $match[2]), 'latest' => $match[3]];
            }
        }

        return $outdated;
    }

    /**
     * Only an owner/repo can be installed; a local extension has to be linked by hand.
     */
    protected function canInstall(Package $package): bool
    {
        return str_contains($package->name, '/');
    }

    /**
     * A pin from the manifest becomes --pin.
     */
    protected function installCommand(Package $package): array
    {
        $pin = $package->version === null ? [] : ['--pin', $package->version];

        return [$this->binary('gh'), 'extension', 'install', $this->spec->argument($package), ...$pin];
    }

    /**
     * A pin is one tag or commit, and gh lists a commit by its first 8 characters.
     */
    protected function pinAllows(string $pin, string $version): bool
    {
        return $pin === $version || (strlen($version) === 8 && str_starts_with($pin, $version));
    }

    /**
     * gh won't install over an extension that's there, so the old one goes first.
     */
    protected function reinstallCommands(Package $package): array
    {
        return [$this->uninstallCommand($package), $this->installCommand($package)];
    }

    /**
     * gh strips owner/ and gh- itself, so the full name works.
     */
    protected function uninstallCommand(Package $package): array
    {
        return [$this->binary('gh'), 'extension', 'remove', $package->name];
    }
}
