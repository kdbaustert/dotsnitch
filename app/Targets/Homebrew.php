<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ManifestFormat;
use App\Data\Package;
use App\Data\ProcessResult;
use App\Manifests\BrewfileFormat;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Formulae, casks and taps. Everything comes from one `brew info --json=v2 --installed` call per
 * scan, which also carries the aliases, dependencies and deprecation flags.
 */
final class Homebrew extends PackageManagerTarget
{
    /** Keeps brew from updating itself or printing hints in the middle of a scan. */
    public const ENVIRONMENT = ['HOMEBREW_NO_AUTO_UPDATE' => '1', 'HOMEBREW_NO_ENV_HINTS' => '1'];

    public const UNKNOWN_NAME = "Homebrew doesn't know this name";

    /** brew stops at the first unknown name and names it on stderr. */
    private const UNAVAILABLE = [
        'formula' => '/No available formula with the name "([^"]+)"/',
        'cask' => "/Cask '([^']+)' is unavailable/",
    ];

    /** brew info flag => drift kind and the prefix of its reason and replacement fields, worst first. */
    private const FLAGS = [
        'disabled' => [DriftKind::Disabled, 'disable'],
        'deprecated' => [DriftKind::Deprecated, 'deprecation'],
    ];

    /** @var array<string, array<string, mixed>> raw brew info entries by Package key */
    private array $entries = [];

    /** @var array<string, string> every alias, old name and short name to its Package key */
    private array $names = [];

    /** @var list<string> tapped taps, lowercase */
    private array $taps = [];

    /** @var array<string, Package> the successor brew names for a flagged entry, by Package key */
    private array $replacements = [];

    /** @var array<string, true> listed entries brew won't install: unknown names and disabled ones */
    private array $notInstallable = [];

    /**
     * The command's name, as typed in --checks=brew and saved under ignore.brew.
     */
    public function key(): string
    {
        return 'brew';
    }

    /**
     * The full name, where the key is the brew command.
     */
    public function label(): string
    {
        return 'Homebrew';
    }

    /**
     * True when a Homebrew prefix exists, even if brew isn't on PATH.
     */
    public function isAvailable(): bool
    {
        return $this->paths->homebrewPrefix() !== null;
    }

    /**
     * The usual name and the hidden one `brew bundle --global` reads.
     */
    public function manifestPatterns(): array
    {
        return ['Brewfile', '.Brewfile'];
    }

    /**
     * Every Brewfile, base or overlay, reads the same way.
     */
    protected function format(string $manifest): ManifestFormat
    {
        return new BrewfileFormat($this->shell, $this->brew());
    }

    /**
     * Loads the brew info JSON and tap list that the other hooks read from.
     *
     * @throws RuntimeException when brew info or brew tap fails
     */
    protected function installed(): Collection
    {
        $info = $this->info(['--installed']);
        $this->entries = [];
        $this->names = [];

        foreach ($info['formulae'] as $formula) {
            $key = $this->remember("formula:{$formula['full_name']}", $formula);
            $this->alias($key, [$formula['name'], ...$formula['aliases'], ...$formula['oldnames']]);
        }

        foreach ($info['casks'] as $cask) {
            $key = $this->remember("cask:{$cask['full_token']}", $cask);
            $this->alias($key, [$cask['token'], ...$cask['old_tokens']]);
        }

        $this->taps = $this->tappedTaps();

        return collect($this->entries)
            ->map(fn (array $entry, string $key) => $this->packageFor($key, $entry))
            ->values()
            ->concat(array_map(fn (string $tap) => new Package($tap, 'tap'), $this->taps));
    }

    /**
     * Maps an alias, old name or short name to the full name brew reports, so either spelling in the
     * Brewfile matches. Tap names are case-insensitive.
     */
    protected function canonical(Package $package): Package
    {
        if ($package->type === 'tap') {
            return new Package(strtolower($package->name), 'tap');
        }

        $key = $this->names["{$package->type}:{$package->name}"] ?? null;

        return $key === null ? $package : $this->packageFor($key, $this->entries[$key]);
    }

    /**
     * Dependencies of listed packages, anything brew installed as a dependency, and the taps
     * that listed packages come from.
     */
    protected function trackedImplicitly(Collection $listed): array
    {
        $tracked = [];

        foreach ($this->entries as $key => $entry) {
            if (str_starts_with($key, 'formula:') && ! ($this->currentInstall($entry)['installed_on_request'] ?? false)) {
                $tracked[] = $key;
            }
        }

        foreach ($listed as $key => $package) {
            $tracked = [...$tracked, ...$this->tapsOf($package), ...$this->dependenciesOf($key)];
        }

        return array_values(array_unique($tracked));
    }

    /**
     * Deprecated and disabled flags for listed entries, and a note on missing ones Homebrew
     * doesn't know. Installed entries come from the cached JSON; missing ones cost one more
     * brew info call per type.
     */
    protected function otherDrift(Collection $listed, Collection $installed, callable $log): array
    {
        $this->replacements = [];
        $this->notInstallable = [];
        $entries = array_intersect_key($this->entries, $listed->all());
        $missing = $listed->diffKeys($installed)->filter(fn (Package $package) => $this->canLookUp($package));
        $items = [];

        foreach (['formula', 'cask'] as $type) {
            $names = $missing->where('type', $type)->pluck('name')->values()->all();

            if ($names === []) {
                continue;
            }

            $log("Checking {$this->plural($type)} that aren't installed");
            [$found, $unknown, $error] = $this->lookUp($type, $names);

            if ($error !== null) {
                $log("Couldn't check missing {$this->plural($type)}: {$error}");
            }

            $entries = [...$entries, ...$found];

            foreach ($unknown as $name) {
                $this->notInstallable["{$type}:{$name}"] = true;
                $items[] = new DriftItem($this->key(), DriftKind::Missing, $listed["{$type}:{$name}"], self::UNKNOWN_NAME);
            }
        }

        foreach ($entries as $key => $entry) {
            $item = $this->flagged($listed[$key], $entry);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * A tap goes through `brew tap`. Formulae and casks get the type flag, so a name both use can't
     * pick the wrong one.
     */
    protected function installCommand(Package $package): array
    {
        return $package->type === 'tap'
            ? [$this->brew(), 'tap', $package->name]
            : [$this->brew(), 'install', $this->typeFlag($package), $package->name];
    }

    /**
     * The reverse of installCommand(), with untap for a tap.
     */
    protected function uninstallCommand(Package $package): array
    {
        return $package->type === 'tap'
            ? [$this->brew(), 'untap', $package->name]
            : [$this->brew(), 'uninstall', $this->typeFlag($package), $package->name];
    }

    /**
     * Installs and uninstalls run with the scan's variables, so brew doesn't stop to update itself.
     */
    protected function environment(): array
    {
        return self::ENVIRONMENT;
    }

    /**
     * The full name plus every alias, old name and short name the last scan saw for it.
     */
    protected function spellingsOf(Package $package): array
    {
        $names = array_keys(array_filter($this->names, fn (string $key) => $key === $package->key()));
        $spellings = array_map(fn (string $key) => new Package(substr($key, strlen($package->type) + 1), $package->type), $names);

        return collect([$package, ...$spellings])->unique('name')->values()->all();
    }

    /**
     * @return ?Package the successor the last scan read from brew info, null when it named none
     */
    protected function replacementFor(Package $package): ?Package
    {
        return $this->replacements[$package->key()] ?? null;
    }

    /**
     * False for a name brew doesn't know and for a disabled entry.
     */
    protected function canInstall(Package $package): bool
    {
        return ! isset($this->notInstallable[$package->key()]);
    }

    /**
     * @param  list<string>  $arguments  such as ['--installed'] or ['--cask', $name]
     * @return array{formulae: list<array<string, mixed>>, casks: list<array<string, mixed>>}
     *
     * @throws RuntimeException when brew fails or prints something other than JSON
     */
    private function info(array $arguments): array
    {
        $result = $this->brewRun(['info', '--json=v2', ...$arguments]);

        if (! $result->successful()) {
            throw new RuntimeException('brew info failed: '.trim($result->errorOutput));
        }

        return $this->decodeInfo($result);
    }

    /**
     * @return array{formulae: list<array<string, mixed>>, casks: list<array<string, mixed>>}
     *
     * @throws RuntimeException when the output isn't brew info JSON
     */
    private function decodeInfo(ProcessResult $result): array
    {
        $info = json_decode($result->output, true);

        if (! is_array($info) || ! isset($info['formulae'], $info['casks'])) {
            throw new RuntimeException("brew info didn't return the expected JSON");
        }

        return $info;
    }

    /**
     * Drops each name brew says doesn't exist and asks again, so one typo doesn't hide the
     * flags on the rest. Returns entries by Package key, the names brew doesn't know, and brew's
     * error when it failed some other way.
     *
     * @param  'formula'|'cask'  $type
     * @param  list<string>  $names
     * @return array{array<string, array<string, mixed>>, list<string>, ?string}
     *
     * @throws RuntimeException when brew exits 0 but doesn't print brew info JSON
     */
    private function lookUp(string $type, array $names): array
    {
        $unknown = [];

        while ($names !== []) {
            $result = $this->brewRun(['info', '--json=v2', "--{$type}", ...$names]);

            if ($result->successful()) {
                return [$this->keyedByAskedName($this->decodeInfo($result)[$this->plural($type)], $names), $unknown, null];
            }

            if (! preg_match(self::UNAVAILABLE[$type], $result->errorOutput, $match) || ! in_array($match[1], $names, true)) {
                return [[], $unknown, trim($result->errorOutput)];
            }

            $unknown[] = $match[1];
            $names = array_values(array_diff($names, [$match[1]]));
        }

        return [[], $unknown, null];
    }

    /**
     * Keys each entry by the name the Brewfile used, since a missing alias comes back under its
     * full name and the alias map only knows installed packages.
     *
     * @param  list<array<string, mixed>>  $entries  all formulae or all casks
     * @param  list<string>  $asked
     * @return array<string, array<string, mixed>>
     */
    private function keyedByAskedName(array $entries, array $asked): array
    {
        $keyed = [];

        foreach ($entries as $entry) {
            $type = isset($entry['full_token']) ? 'cask' : 'formula';
            $names = $type === 'formula'
                ? [$entry['full_name'], $entry['name'], ...$entry['aliases'], ...$entry['oldnames']]
                : [$entry['full_token'], $entry['token'], ...$entry['old_tokens']];

            foreach (array_intersect($asked, $names) as $name) {
                $keyed["{$type}:{$name}"] = $entry;
            }
        }

        return $keyed;
    }

    /**
     * Disabled wins, since a disabled entry is also deprecated and can't be installed. Also notes
     * the successor and whether it can still be installed, for actionsFor() and steps().
     *
     * @param  array<string, mixed>  $entry
     */
    private function flagged(Package $package, array $entry): ?DriftItem
    {
        foreach (self::FLAGS as $flag => [$kind, $prefix]) {
            if (! ($entry[$flag] ?? false)) {
                continue;
            }

            $replacement = $this->replacementIn($entry, $prefix);
            $reason = $entry["{$prefix}_reason"] ?? null;

            if ($replacement !== null) {
                $this->replacements[$package->key()] = $replacement;
            }

            if ($kind === DriftKind::Disabled) {
                $this->notInstallable[$package->key()] = true;
            }

            $detail = match (true) {
                $replacement !== null => "{$kind->value}, use `{$replacement->name}` instead",
                $reason !== null => "{$kind->value} upstream ({$reason})",
                default => "{$kind->value} upstream",
            };

            return new DriftItem($this->key(), $kind, $package, $detail);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  string  $prefix  "deprecation" or "disable"
     */
    private function replacementIn(array $entry, string $prefix): ?Package
    {
        foreach (['formula', 'cask'] as $type) {
            $name = $entry["{$prefix}_replacement_{$type}"] ?? null;

            if (is_string($name)) {
                return new Package($name, $type);
            }
        }

        return null;
    }

    /**
     * @return list<string> tap keys: the package's own tap, plus the one in a qualified name
     */
    private function tapsOf(Package $package): array
    {
        $taps = [];
        $tap = $this->entries[$package->key()]['tap'] ?? null;

        if (is_string($tap)) {
            $taps[] = 'tap:'.strtolower($tap);
        }

        if (substr_count($package->name, '/') === 2) {
            $taps[] = 'tap:'.strtolower(dirname($package->name));
        }

        return $taps;
    }

    /**
     * @return list<string> formula and cask keys this installed entry depends on
     */
    private function dependenciesOf(string $key): array
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return [];
        }

        $install = str_starts_with($key, 'formula:') ? $this->currentInstall($entry) : null;
        $formulae = array_column($install['runtime_dependencies'] ?? [], 'full_name');
        $formulae = [...$formulae, ...($entry['depends_on']['formula'] ?? [])];
        $casks = $entry['depends_on']['cask'] ?? [];

        return [
            ...array_map(fn (string $name) => $this->names["formula:{$name}"] ?? "formula:{$name}", $formulae),
            ...array_map(fn (string $name) => $this->names["cask:{$name}"] ?? "cask:{$name}", $casks),
        ];
    }

    /**
     * A qualified name from a tap that isn't tapped would make brew info tap it, so skip those.
     */
    private function canLookUp(Package $package): bool
    {
        return substr_count($package->name, '/') !== 2
            || in_array(strtolower(dirname($package->name)), $this->taps, true);
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException when brew tap fails
     */
    private function tappedTaps(): array
    {
        $result = $this->brewRun(['tap']);

        if (! $result->successful()) {
            throw new RuntimeException('brew tap failed: '.trim($result->errorOutput));
        }

        return array_values(array_filter(array_map(
            fn (string $line) => strtolower(trim($line)),
            explode("\n", $result->output),
        )));
    }

    /**
     * @param  string  $key  "type:full_name", as brew reports it
     * @param  array<string, mixed>  $entry
     */
    private function remember(string $key, array $entry): string
    {
        $this->entries[$key] = $entry;
        $this->names[$key] = $key;

        return $key;
    }

    /**
     * @param  list<string>  $names
     */
    private function alias(string $key, array $names): void
    {
        $type = strstr($key, ':', true);

        foreach ($names as $name) {
            $this->names["{$type}:{$name}"] ??= $key;
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function packageFor(string $key, array $entry): Package
    {
        [$type, $name] = explode(':', $key, 2);
        $version = $type === 'formula' ? ($this->currentInstall($entry)['version'] ?? null) : ($entry['installed'] ?? null);

        return new Package($name, $type, is_string($version) ? $version : null);
    }

    /**
     * The linked keg when several versions are installed, otherwise the newest.
     *
     * @param  array<string, mixed>  $formula
     * @return ?array<string, mixed> null for a formula that isn't installed
     */
    private function currentInstall(array $formula): ?array
    {
        $installs = $formula['installed'] ?? [];

        foreach ($installs as $install) {
            if ($install['version'] === ($formula['linked_keg'] ?? null)) {
                return $install;
            }
        }

        return $installs === [] ? null : end($installs);
    }

    /**
     * --formula or --cask, so brew never guesses when a formula and a cask share a name.
     */
    private function typeFlag(Package $package): string
    {
        return $package->type === 'cask' ? '--cask' : '--formula';
    }

    /**
     * For log lines, such as "Checking formulae that aren't installed".
     */
    private function plural(string $type): string
    {
        return $type === 'formula' ? 'formulae' : 'casks';
    }

    /**
     * @param  list<string>  $arguments
     */
    private function brewRun(array $arguments): ProcessResult
    {
        return $this->shell->run([$this->brew(), ...$arguments], self::LOOKUP_TIMEOUT, self::ENVIRONMENT);
    }

    /**
     * @throws RuntimeException when Homebrew isn't installed
     */
    private function brew(): string
    {
        $prefix = $this->paths->homebrewPrefix() ?? throw new RuntimeException('Homebrew is not installed');

        return "{$prefix}/bin/brew";
    }
}
