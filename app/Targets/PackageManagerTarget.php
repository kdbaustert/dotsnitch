<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ComparesSides;
use App\Contracts\ManifestEdit;
use App\Contracts\ManifestFormat;
use App\Contracts\PackageSpec;
use App\Contracts\Shell;
use App\Contracts\SyncTarget;
use App\Data\Package;
use App\Manifests\BashArrayFormat;
use App\Manifests\BrewfileLineFormat;
use App\Manifests\LineListFormat;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\DriftReport;
use App\Sync\Step;
use Closure;
use Illuminate\Support\Collection;
use LogicException;
use RuntimeException;

/**
 * Diffs what the manifests list against what's installed, and turns decisions into install
 * commands and manifest edits. Subclasses say how to read each side and which commands to run.
 */
abstract class PackageManagerTarget implements ComparesSides, SyncTarget
{
    /** Seconds. An install can build from source, so it gets far longer than a lookup. */
    private const INSTALL_TIMEOUT = 1800;

    /** Seconds, for a command that only lists what's installed. */
    protected const LOOKUP_TIMEOUT = 120;

    /** Drift kinds where the manifest entry itself is the problem. */
    private const FLAGGED = [DriftKind::Deprecated, DriftKind::Disabled];

    /** @var array<string, Package> what the last scan() found installed, by Package key */
    private array $installedByKey = [];

    /** @var array<string, list<string>> the manifests the last scan() found each package in, as file names by Package key, with the folder when two share a name */
    private array $listedIn = [];

    /**
     * @param  PathResolver  $paths  finds a tool even when it isn't on the PATH dotsnitch started with
     * @param  ManifestEditor  $editor  backs up each manifest before its first edit in a run
     */
    public function __construct(
        protected readonly Shell $shell,
        protected readonly PathResolver $paths,
        private readonly ManifestEditor $editor,
    ) {}

    /**
     * The reader for one manifest. A manager can keep its list in more than one kind of file, such
     * as a package.json or a plain list, so the path picks it.
     */
    abstract protected function format(string $manifest): ManifestFormat;

    /**
     * Everything installed, including what was pulled in as a dependency. Use trackedImplicitly()
     * to keep dependencies out of the untracked list. Runs before canonical() and
     * trackedImplicitly(), so it can load what they need.
     *
     * @return Collection<int, Package>
     */
    abstract protected function installed(): Collection;

    /**
     * @return list<string> the full command, binary path first
     */
    abstract protected function installCommand(Package $package): array;

    /**
     * @return list<string> the full command, binary path first
     */
    abstract protected function uninstallCommand(Package $package): array;

    /**
     * Base files and overlays are merged, so an entry in any of them counts as listed.
     */
    public function scan(array $manifests, callable $log): DriftReport
    {
        $entries = [];

        foreach ($manifests as $manifest) {
            $log('Reading '.basename($manifest));
            $entries[$manifest] = $this->format($manifest)->read($manifest);
            $log(basename($manifest)." lists {$entries[$manifest]->count()}");
        }

        $log("Listing installed {$this->label()} packages");
        $installed = $this->installed()->keyBy(fn (Package $package) => $package->key());
        $this->installedByKey = $installed->all();
        $log("{$installed->count()} installed");
        /** @var Collection<string, Package> $listed */
        $listed = collect();
        $this->listedIn = [];
        $sharingName = array_count_values(array_map(basename(...), $manifests));

        foreach ($entries as $manifest => $packages) {
            // mac/Brewfile and work/Brewfile would both read as Brewfile, so those keep their folder.
            $name = $sharingName[basename($manifest)] > 1 ? basename(dirname($manifest)).'/'.basename($manifest) : basename($manifest);

            foreach ($packages->map($this->canonical(...)) as $package) {
                $listed[$package->key()] = $package;
                $this->listedIn[$package->key()][] = $name;
            }
        }

        $tracked = [...$listed->keys(), ...$this->trackedImplicitly($listed)];

        $missing = $listed->diffKeys($installed)
            ->map(fn (Package $package) => new DriftItem($this->key(), DriftKind::Missing, $package));
        $untracked = $installed->except($tracked)
            ->map(fn (Package $package) => new DriftItem($this->key(), DriftKind::Untracked, $package));
        $offPin = $listed->intersectByKeys($installed)
            ->filter(fn (Package $package, string $key) => $package->version !== null
                && $installed[$key]->version !== null
                && ! $this->pinAllows($package->version, $installed[$key]->version))
            ->map(fn (Package $package) => new DriftItem($this->key(), DriftKind::Version, $package, "pinned to {$package->version}"));

        // An otherDrift() item with the same key replaces the plain one, so a check can explain a
        // missing entry without listing it twice.
        $items = collect([...$missing->values(), ...$untracked->values(), ...$offPin->values(), ...$this->otherDrift($listed, $installed, $log)])
            ->keyBy(fn (DriftItem $item) => $item->key());

        return new DriftReport($this->key(), $items->values()->all());
    }

    /**
     * Install is left out for a package that can't be installed, and Replace for a flagged entry
     * with no known successor.
     */
    public function actionsFor(DriftItem $item): array
    {
        $package = $this->packageOf($item);
        $actions = match ($item->kind) {
            DriftKind::Missing => $this->canInstall($package)
                ? [Action::Install, Action::RemoveFromManifest, Action::Ignore]
                : [Action::RemoveFromManifest, Action::Ignore],
            DriftKind::Untracked => [Action::AddToManifest, Action::Uninstall, Action::Ignore],
            DriftKind::Version => [Action::Install, Action::Ignore],
            DriftKind::Deprecated, DriftKind::Disabled => $this->replacementFor($package) === null
                ? [Action::RemoveFromManifest, Action::Ignore]
                : [Action::Replace, Action::RemoveFromManifest, Action::Ignore],
            default => [],
        };
        $labels = [];

        foreach ([...$actions, Action::Skip] as $action) {
            $labels[$action->value] = $this->labelFor($action, $item);
        }

        return $labels;
    }

    /**
     * Replace installs the successor first, so a failed install leaves the old package and the
     * manifest as they were.
     */
    public function steps(Decision $decision): array
    {
        $package = $this->packageOf($decision->item);

        return match ($decision->action) {
            Action::Install => $decision->item->kind === DriftKind::Version
                ? array_map($this->commandStep(...), $this->reinstallCommands($package))
                : [$this->commandStep($this->installCommand($package), [$this->uninstallCommand($package)])],
            Action::Uninstall => [$this->commandStep($this->uninstallCommand($package), $this->installingAgain($package))],
            Action::AddToManifest => [$this->addStep($decision->manifests, $package)],
            Action::RemoveFromManifest => $this->removeSteps($decision->manifests, $package),
            Action::Replace => $this->replaceSteps($decision->manifests, $package),
            Action::Ignore, Action::Skip => [],
            default => throw new LogicException("{$this->label()} can't {$decision->action->value} a package"),
        };
    }

    /**
     * Read from the last scan(), so a deprecated entry says whether it's installed too.
     */
    public function sides(DriftItem $item): array
    {
        $key = $this->packageOf($item)->key();
        $files = array_unique($this->listedIn[$key] ?? []);
        $installed = $this->installedByKey[$key] ?? null;

        return [
            'dotfiles' => $files === [] ? 'not listed' : 'listed in '.implode(', ', $files),
            'mac' => match (true) {
                $installed === null => 'not installed',
                $installed->version === null => 'installed',
                default => "installed {$installed->version}",
            },
        ];
    }

    /**
     * Rewrites a manifest entry to the name installed() uses, such as an alias to its formula.
     */
    protected function canonical(Package $package): Package
    {
        return $package;
    }

    /**
     * Keys that aren't in a manifest but shouldn't be reported as untracked, such as dependencies.
     *
     * @param  Collection<string, Package>  $listed  keyed by Package::key(), already canonical
     * @return list<string> Package keys
     */
    protected function trackedImplicitly(Collection $listed): array
    {
        return [];
    }

    /**
     * Drift beyond missing and untracked, such as Homebrew's deprecated entries. Returning a
     * Missing or Untracked item replaces the plain one for that package, keeping its place.
     *
     * @param  Collection<string, Package>  $listed
     * @param  Collection<string, Package>  $installed
     * @param  callable(string): void  $log
     * @return list<DriftItem>
     */
    protected function otherDrift(Collection $listed, Collection $installed, callable $log): array
    {
        return [];
    }

    /**
     * Extra variables for install and uninstall commands.
     *
     * @return array<string, string>
     */
    protected function environment(): array
    {
        return [];
    }

    /**
     * Every name a manifest might use for this package, so a remove finds an alias too.
     *
     * @return list<Package> the package itself first
     */
    protected function spellingsOf(Package $package): array
    {
        return [$package];
    }

    /**
     * The successor the last scan found for a deprecated or disabled entry.
     */
    protected function replacementFor(Package $package): ?Package
    {
        return null;
    }

    /**
     * False for a listed name the manager can't install, such as one it doesn't know.
     */
    protected function canInstall(Package $package): bool
    {
        return true;
    }

    /**
     * Whether an installed version is one the manifest's pin asks for. Reads a whole or partial
     * version, such as 6 or 14.1.1, and a caret range. Anything else, such as a tag or a wider
     * range, passes, so a pin dotsnitch can't read is never reported.
     *
     * @param  string  $pin  what follows the name in the manifest
     */
    protected function pinAllows(string $pin, string $version): bool
    {
        if (! preg_match('/^(\^?)v?(\d+(?:\.\d+)*)$/', $pin, $match)) {
            return true;
        }

        $version = ltrim($version, 'v');

        if ($match[1] === '') {
            return $version === $match[2] || str_starts_with($version, $match[2].'.');
        }

        // ^1.2 stops before 2, and ^0.3 before 0.4: the first part that isn't 0 is the one that can't change.
        $parts = array_map(intval(...), explode('.', $match[2]));
        $fixed = array_find_key($parts, fn (int $part) => $part > 0) ?? array_key_last($parts);
        $ceiling = implode('.', [...array_slice($parts, 0, $fixed), $parts[$fixed] + 1]);

        return version_compare($version, $match[2], '>=') && version_compare($version, $ceiling, '<');
    }

    /**
     * The commands that put the pinned version in place of the one installed.
     *
     * @return list<list<string>>
     */
    protected function reinstallCommands(Package $package): array
    {
        return [$this->installCommand($package)];
    }

    /**
     * True for name.json and for its overlays, such as name.json.mac or a hostname with dots in it,
     * like name.json.kennys-mbp.local.
     *
     * @param  string  $extension  without the dot
     */
    protected function hasExtension(string $manifest, string $extension): bool
    {
        return preg_match('/\\.'.preg_quote($extension, '/').'(\\..+)?$/', basename($manifest)) === 1;
    }

    /**
     * @return string the tool's full path, such as /opt/homebrew/bin/gh
     *
     * @throws RuntimeException when it isn't on PATH
     */
    protected function binary(string $name): string
    {
        return $this->paths->find($name) ?? throw new RuntimeException("{$name} is not installed");
    }

    /**
     * Runs a lookup and decodes the JSON it prints. npm ls exits 1 over something like a missing
     * peer dependency but still prints the whole list, so output that decodes counts.
     *
     * @param  list<string>  $command
     * @return array<mixed>
     *
     * @throws RuntimeException when there's no JSON to read
     */
    protected function json(array $command): array
    {
        $result = $this->shell->run($command, self::LOOKUP_TIMEOUT);
        $data = json_decode($result->output, true);

        if (! is_array($data)) {
            $error = trim($result->errorOutput) ?: "exited with code {$result->exitCode}";

            throw new RuntimeException(basename($command[0])." {$command[1]} printed no JSON: {$error}");
        }

        return $data;
    }

    /**
     * Runs a lookup that prints plain text.
     *
     * @param  list<string>  $command
     * @return list<string> stdout's lines, blank ones dropped
     *
     * @throws RuntimeException when the command fails
     */
    protected function lines(array $command): array
    {
        $result = $this->shell->run($command, self::LOOKUP_TIMEOUT);

        if (! $result->successful()) {
            $error = trim($result->errorOutput) ?: "exited with code {$result->exitCode}";

            throw new RuntimeException(basename($command[0])." {$command[1]} failed: {$error}");
        }

        return array_values(array_filter(explode("\n", $result->output), fn (string $line) => trim($line) !== ''));
    }

    /**
     * SharesManifest's answer for the checks that read their own lines of a Brewfile.
     */
    public function hasEntriesIn(string $path): bool
    {
        return ! BrewfileLineFormat::isBrewfile($path) || $this->format($path)->read($path)->isNotEmpty();
    }

    /**
     * A .sh reads its bash arrays; anything else is a list of names.
     */
    protected function scriptOrList(string $manifest, PackageSpec $spec): ManifestFormat
    {
        return $this->hasExtension($manifest, 'sh') ? new BashArrayFormat($spec) : new LineListFormat($spec);
    }

    /**
     * @throws LogicException for a symlink item, which a package check never reports
     */
    private function packageOf(DriftItem $item): Package
    {
        return $item->subject instanceof Package
            ? $item->subject
            : throw new LogicException("{$this->label()} got a drift item that isn't a package: {$item->key()}");
    }

    /**
     * "Keep" reads better than "Ignore" for an entry that's only flagged upstream.
     */
    private function labelFor(Action $action, DriftItem $item): string
    {
        return match (true) {
            $action === Action::Replace => 'Replace with '.$this->replacementFor($this->packageOf($item))?->name,
            $action === Action::Ignore && in_array($item->kind, self::FLAGGED, true) => 'Keep (ignore on this Mac)',
            $action === Action::Install && $item->kind === DriftKind::Version => "Install {$this->packageOf($item)->version}",
            default => $action->label(),
        };
    }

    /**
     * @param  list<string>  $command
     * @param  list<list<string>>  $undo  what `undo --packages` runs to take it back; empty when nothing can
     */
    private function commandStep(array $command, array $undo = []): Step
    {
        return new Step(Step::commandLine($command), function () use ($command, $undo) {
            $result = $this->shell->run($command, self::INSTALL_TIMEOUT, $this->environment());

            if (! $result->successful()) {
                throw new RuntimeException(trim($result->errorOutput) ?: trim($result->output) ?: "Exited with code {$result->exitCode}");
            }

            return $undo === []
                ? ['kind' => 'command', 'command' => $command]
                : ['kind' => 'command', 'command' => $command, 'undo' => $undo, 'environment' => $this->environment()];
        });
    }

    /**
     * The commands that bring back a package an uninstall removed, at whatever version is current.
     *
     * @return list<list<string>> empty for one the manager can't install
     */
    private function installingAgain(Package $package): array
    {
        $unpinned = new Package($package->name, $package->type);

        return $this->canInstall($unpinned) ? [$this->installCommand($unpinned)] : [];
    }

    /**
     * @param  list<string>  $manifests  the destination only
     *
     * @throws RuntimeException when no destination was chosen
     */
    private function addStep(array $manifests, Package $package): Step
    {
        $manifest = $manifests[0] ?? throw new RuntimeException("No manifest to add {$package->name} to");

        return new Step(
            "Add {$package->key()} to ".basename($manifest),
            fn () => $this->editor->edit($manifest, function (string $contents) use ($manifest, $package) {
                $edit = $this->format($manifest)->edit($contents);
                $edit->add($package);

                return $edit->contents();
            }),
        );
    }

    /**
     * @param  list<string>  $manifests
     * @return list<Step> one per manifest that lists the package
     */
    private function removeSteps(array $manifests, Package $package): array
    {
        $removal = $this->editing($package, fn (ManifestEdit $edit, Package $spelling) => $edit->remove($spelling));

        return array_map(
            fn (string $manifest) => new Step(
                "Remove {$package->key()} from ".basename($manifest),
                fn () => $this->editor->edit($manifest, $removal($manifest)),
            ),
            $this->manifestsListing($manifests, $package),
        );
    }

    /**
     * Installs the successor, uninstalls the old package if it's installed, then swaps the
     * entry in every manifest that lists it.
     *
     * @param  list<string>  $manifests
     * @return list<Step>
     *
     * @throws RuntimeException when the last scan found no successor
     */
    private function replaceSteps(array $manifests, Package $package): array
    {
        $replacement = $this->replacementFor($package) ?? throw new RuntimeException("No replacement is known for {$package->name}");
        $swap = $this->editing($package, fn (ManifestEdit $edit, Package $spelling) => $edit->replace($spelling, $replacement));
        $steps = [$this->commandStep($this->installCommand($replacement), [$this->uninstallCommand($replacement)])];

        if (isset($this->installedByKey[$package->key()])) {
            $steps[] = $this->commandStep($this->uninstallCommand($package), $this->installingAgain($package));
        }

        foreach ($this->manifestsListing($manifests, $package) as $manifest) {
            $steps[] = new Step(
                "Replace {$package->key()} with {$replacement->key()} in ".basename($manifest),
                fn () => $this->editor->edit($manifest, $swap($manifest)),
            );
        }

        return $steps;
    }

    /**
     * Builds a ManifestEditor change that runs $change once per spelling of the package.
     *
     * @param  Closure(ManifestEdit, Package): bool  $change
     * @return Closure(string): (Closure(string): string) takes the manifest path, then its contents
     */
    private function editing(Package $package, Closure $change): Closure
    {
        return fn (string $manifest) => function (string $contents) use ($manifest, $package, $change) {
            $edit = $this->format($manifest)->edit($contents);

            foreach ($this->spellingsOf($package) as $spelling) {
                $change($edit, $spelling);
            }

            return $edit->contents();
        };
    }

    /**
     * @param  list<string>  $manifests
     * @return list<string>
     *
     * @throws RuntimeException when none of them has a line for the package that can be edited
     */
    private function manifestsListing(array $manifests, Package $package): array
    {
        $listing = array_values(array_filter($manifests, function (string $manifest) use ($package) {
            $edit = $this->format($manifest)->edit(is_file($manifest) ? (string) file_get_contents($manifest) : '');

            return array_any($this->spellingsOf($package), fn (Package $spelling) => $edit->lists($spelling));
        }));

        if ($listing === []) {
            $files = implode(', ', array_map(basename(...), $manifests)) ?: 'no manifest';

            throw new RuntimeException("{$package->name} isn't on a line dotsnitch can edit in {$files}. Edit it by hand.");
        }

        return $listing;
    }
}
