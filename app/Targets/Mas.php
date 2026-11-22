<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ManifestFormat;
use App\Contracts\SharesManifest;
use App\Data\Package;
use App\Manifests\BrewfileLineFormat;
use App\Manifests\MasSpec;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\DriftReport;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Mac App Store apps, by id. Ids don't say much in a report, so each item shows the app's name
 * from the manifest or from mas list.
 */
final class Mas extends PackageManagerTarget implements SharesManifest
{
    /** mas list's text form: id, name, then the version in parens. */
    private const LIST_LINE = '/^\s*(\d+)\s+(.+?)\s+\((.+)\)\s*$/';

    /** @var array<string, string> app name by id, from the manifests and the last installed() */
    private array $titles = [];

    /**
     * The mas CLI's name, which its manifests share: mas.txt, Masfile, mas.sh.
     */
    public function key(): string
    {
        return 'mas';
    }

    /**
     * The store's name, since mas is only the tool that reads it.
     */
    public function label(): string
    {
        return 'Mac App Store';
    }

    /**
     * False without the mas CLI, even though the App Store itself is always there.
     */
    public function isAvailable(): bool
    {
        return $this->paths->find('mas') !== null;
    }

    /**
     * mas.sh is a setup script whose bash array holds "id:Name" entries. A Brewfile counts for
     * its mas lines.
     */
    public function manifestPatterns(): array
    {
        return ['mas.txt', 'Masfile', 'mas.sh', 'Brewfile', '.Brewfile'];
    }

    public function hasEntriesIn(string $path): bool
    {
        return ! BrewfileLineFormat::isBrewfile($path) || $this->format($path)->read($path)->isNotEmpty();
    }

    /**
     * Picks up the names next to each id before the scan, so missing apps get one too.
     */
    public function scan(array $manifests, callable $log): DriftReport
    {
        $this->titles = [];

        foreach ($manifests as $manifest) {
            $contents = is_file($manifest) ? (string) file_get_contents($manifest) : '';
            $this->titles += BrewfileLineFormat::isBrewfile($manifest) ? BrewfileLineFormat::appTitles($contents) : MasSpec::titlesIn($contents);
        }

        return parent::scan($manifests, $log);
    }

    /**
     * Builds a new MasSpec each time, since it carries the app names scan() just collected.
     */
    protected function format(string $manifest): ManifestFormat
    {
        return BrewfileLineFormat::isBrewfile($manifest)
            ? new BrewfileLineFormat('app', $this->titles)
            : $this->scriptOrList($manifest, new MasSpec($this->titles));
    }

    /**
     * Reads the text list, not --json. mas 7's JSON prints {"name":""} for some installed apps,
     * dropping their id, while the text list has them all. Also notes each app's name.
     *
     * @throws RuntimeException when mas list fails
     */
    protected function installed(): Collection
    {
        $packages = collect();

        foreach ($this->lines([$this->binary('mas'), 'list']) as $line) {
            if (preg_match(self::LIST_LINE, $line, $match)) {
                $this->titles[$match[1]] = $match[2];
                $packages->push(new Package($match[1], 'app', $match[3]));
            }
        }

        return $packages;
    }

    /**
     * Gives missing and untracked apps their name as the detail.
     */
    protected function otherDrift(Collection $listed, Collection $installed, callable $log): array
    {
        return [
            ...$this->titled(DriftKind::Missing, $listed->diffKeys($installed)),
            ...$this->titled(DriftKind::Untracked, $installed->except($listed->keys())),
        ];
    }

    /**
     * Only works for an app this Apple Account already got, and only when you're signed in to
     * the App Store.
     */
    protected function installCommand(Package $package): array
    {
        return [$this->binary('mas'), 'install', $package->name];
    }

    /**
     * mas needs root to uninstall, so this asks for your password.
     */
    protected function uninstallCommand(Package $package): array
    {
        return ['/usr/bin/sudo', $this->binary('mas'), 'uninstall', $package->name];
    }

    /**
     * @param  Collection<string, Package>  $packages
     * @return list<DriftItem> one per package whose name is known
     */
    private function titled(DriftKind $kind, Collection $packages): array
    {
        return $packages
            ->filter(fn (Package $package) => isset($this->titles[$package->name]))
            ->map(fn (Package $package) => new DriftItem($this->key(), $kind, $package, $this->titles[$package->name]))
            ->values()
            ->all();
    }
}
