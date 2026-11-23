<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ListsOutdated;
use App\Contracts\ManifestFormat;
use App\Contracts\Shell;
use App\Data\Package;
use App\Manifests\NodeSpec;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Global pnpm packages. pnpm 11 puts the packages from each `pnpm add -g` call in a group
 * directory of their own, so installs here run one package per command and an untracked package
 * that shares a group says so before anyone removes it.
 */
final class Pnpm extends PackageManagerTarget implements ListsOutdated
{
    private readonly NodeSpec $spec;

    /** @var array<string, string> group directory by package name, from the last installed() */
    private array $groups = [];

    /**
     * The resolver finds pnpm and tells whether its bin dir is on your login PATH.
     */
    public function __construct(Shell $shell, PathResolver $paths, ManifestEditor $editor)
    {
        parent::__construct($shell, $paths, $editor);
        $this->spec = new NodeSpec;
    }

    /**
     * Its own key, so its manifests and ignore list stay apart from npm's.
     */
    public function key(): string
    {
        return 'pnpm';
    }

    /**
     * Says global, since a project's own packages aren't checked.
     */
    public function label(): string
    {
        return 'pnpm (global)';
    }

    /**
     * Only asks whether pnpm exists. A global bin dir that's off your PATH gets a hint during the scan.
     */
    public function isAvailable(): bool
    {
        return $this->paths->find('pnpm') !== null;
    }

    /**
     * pnpm.sh is a setup script whose bash arrays hold the names.
     */
    public function manifestPatterns(): array
    {
        return ['pnpm-global.txt', '.pnpm-globals', 'pnpm.sh'];
    }

    /**
     * No package.json here, unlike npm. pnpm's globals sit in a plain list or in pnpm.sh's arrays.
     */
    protected function format(string $manifest): ManifestFormat
    {
        return $this->scriptOrList($manifest, $this->spec);
    }

    /**
     * Also notes each package's group directory, the part of its path before node_modules.
     * Before pnpm 11 every global shared the one project directory, which isn't a group.
     *
     * @throws RuntimeException when pnpm prints no JSON
     */
    protected function installed(): Collection
    {
        $packages = collect();
        $this->groups = [];

        foreach ($this->json([$this->binary('pnpm'), 'list', '-g', '--json']) as $project) {
            foreach ($project['dependencies'] ?? [] as $name => $package) {
                $packages->push(new Package($name, 'package', $package['version'] ?? null));
                $group = strstr($package['path'] ?? '', '/node_modules/', true);

                if ($group !== false && $group !== ($project['path'] ?? null)) {
                    $this->groups[$name] = $group;
                }
            }
        }

        return $packages;
    }

    /**
     * pnpm exits 1 when it lists anything, and prints {} when it doesn't.
     *
     * @throws RuntimeException when pnpm prints no JSON
     */
    public function outdated(): array
    {
        $listed = $this->json([$this->binary('pnpm'), 'outdated', '-g', '--format', 'json']);

        return array_map(
            fn (array $entry, string $name) => ['package' => new Package($name, 'package', $entry['current'] ?? null), 'latest' => $entry['latest']],
            $listed,
            array_keys($listed),
        );
    }

    /**
     * Hints at `pnpm setup` when pnpm's bin dir isn't on your PATH, and marks untracked packages
     * that share a group with others.
     */
    protected function otherDrift(Collection $listed, Collection $installed, callable $log): array
    {
        $this->hintAtSetup($log);
        $items = [];

        foreach ($installed->except($listed->keys()) as $package) {
            $groupMates = array_keys($this->groups, $this->groups[$package->name] ?? null, true);
            $others = array_values(array_diff($groupMates, [$package->name]));

            if ($others !== []) {
                $items[] = new DriftItem($this->key(), DriftKind::Untracked, $package, 'shares a pnpm install group with '.implode(', ', $others));
            }
        }

        return $items;
    }

    /**
     * Keeps the manifest's pin, so typescript@6 stays on 6.
     */
    protected function installCommand(Package $package): array
    {
        return [$this->binary('pnpm'), 'add', '-g', $this->spec->argument($package)];
    }

    /**
     * By name only, since a pin doesn't matter for a removal.
     */
    protected function uninstallCommand(Package $package): array
    {
        return [$this->binary('pnpm'), 'remove', '-g', $package->name];
    }

    /**
     * pnpm fails `bin -g` outright when PNPM_HOME was never set up, so a failure gets the hint too.
     *
     * @param  callable(string): void  $log
     */
    private function hintAtSetup(callable $log): void
    {
        $result = $this->shell->run([$this->binary('pnpm'), 'bin', '-g'], self::LOOKUP_TIMEOUT);
        $bin = trim($result->output);

        if (! $result->successful() || $bin === '') {
            $log("pnpm doesn't know its global bin dir. Run `pnpm setup`, then open a new terminal.");
        } elseif (! $this->paths->onLoginPath($bin)) {
            $log("pnpm's bin dir {$bin} isn't on your PATH. Run `pnpm setup`, then open a new terminal.");
        }
    }
}
