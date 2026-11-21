<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ManifestFormat;
use App\Contracts\Shell;
use App\Data\Package;
use App\Manifests\MasSpec;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\DriftReport;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Mac App Store apps, by id. Ids don't say much in a report, so each item shows the app's name
 * from the manifest or from mas list.
 */
final class Mas extends PackageManagerTarget
{
    /** mas list's text form: id, name, then the version in parens. */
    private const LIST_LINE = '/^\s*(\d+)\s+(.+?)\s+\((.+)\)\s*$/';

    /** @var array<string, string> app name by id, from the manifests and the last installed() */
    private array $titles = [];

    /**
     * The resolver finds mas.
     */
    public function __construct(
        Shell $shell,
        private readonly PathResolver $paths,
        ManifestEditor $editor,
    ) {
        parent::__construct($shell, $editor);
    }

    /**
     * Used in --checks and under ignore.mas in config.json.
     */
    public function key(): string
    {
        return 'mas';
    }

    /**
     * Shown in the report.
     */
    public function label(): string
    {
        return 'Mac App Store';
    }

    /**
     * True when mas is on the resolved PATH.
     */
    public function isAvailable(): bool
    {
        return $this->paths->find('mas') !== null;
    }

    /**
     * mas.sh is a setup script whose bash array holds "id:Name" entries.
     */
    public function manifestPatterns(): array
    {
        return ['mas.txt', 'Masfile', 'mas.sh'];
    }

    /**
     * Picks up the names next to each id before the scan, so missing apps get one too.
     */
    public function scan(array $manifests, callable $log): DriftReport
    {
        $this->titles = [];

        foreach ($manifests as $manifest) {
            $this->titles += is_file($manifest) ? MasSpec::titlesIn((string) file_get_contents($manifest)) : [];
        }

        return parent::scan($manifests, $log);
    }

    /**
     * A .sh reads its bash arrays; anything else is a list of ids.
     */
    protected function format(string $manifest): ManifestFormat
    {
        return $this->scriptOrList($manifest, new MasSpec($this->titles));
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

        foreach ($this->lines([$this->mas(), 'list']) as $line) {
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
        return [$this->mas(), 'install', $package->name];
    }

    /**
     * mas needs root to uninstall, so this asks for your password.
     */
    protected function uninstallCommand(Package $package): array
    {
        return ['/usr/bin/sudo', $this->mas(), 'uninstall', $package->name];
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

    /**
     * @throws RuntimeException when mas isn't on PATH
     */
    private function mas(): string
    {
        return $this->paths->find('mas') ?? throw new RuntimeException('mas is not installed');
    }
}
