<?php

declare(strict_types=1);

use App\Contracts\ComparesSides;
use App\Contracts\SyncTarget;
use App\Data\Package;
use App\Support\Machine;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use App\Support\StatusLock;
use App\Support\UserSettings;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\DriftReport;
use App\Sync\ReportStore;
use App\Sync\Step;
use App\Sync\SyncLog;
use App\Targets\Homebrew;
use Tests\Fakes\FakeShell;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * A check that reads any Brewfile and either throws or reports $package, once per kind in $kinds.
 * Adding it appends a line through the real ManifestEditor; installing and uninstalling only record
 * the call in $ran, or fails when $uninstallError is set.
 */
function fakeTarget(string $key, bool $fails, bool $available = true): SyncTarget
{
    return new class($key, $fails, $available) implements ComparesSides, SyncTarget
    {
        /** @var list<string> */
        public array $ran = [];

        /** @var list<string> */
        public array $patterns = ['Brewfile'];

        /** @var list<DriftKind> */
        public array $kinds = [DriftKind::Untracked];

        /** The formula scan() reports. */
        public string $package = 'jq';

        /** Set it to make the uninstall step fail with this message. */
        public ?string $uninstallError = null;

        /**
         * @param  bool  $fails  true makes scan() throw
         */
        public function __construct(private readonly string $key, private readonly bool $fails, private readonly bool $available) {}

        /**
         * The key the test asked for, which is how a failing fake takes Homebrew's place as brew.
         */
        public function key(): string
        {
            return $this->key;
        }

        /**
         * The key with a capital, so "brew" shows as Brew.
         */
        public function label(): string
        {
            return ucfirst($this->key);
        }

        /**
         * True unless the test made it with $available false, to stand in for a tool that isn't
         * installed.
         */
        public function isAvailable(): bool
        {
            return $this->available;
        }

        /**
         * Just Brewfile unless the test set $patterns, so it reads the same file as the real check.
         */
        public function manifestPatterns(): array
        {
            return $this->patterns;
        }

        /**
         * @throws RuntimeException when made with $fails
         */
        public function scan(array $manifests, callable $log): DriftReport
        {
            if ($this->fails) {
                throw new RuntimeException('brew info failed: boom');
            }

            // Reads them the way a real check does, so one that can't be read warns here too.
            array_map(file_get_contents(...), $manifests);

            return new DriftReport($this->key, array_map(
                fn (DriftKind $kind) => new DriftItem($this->key, $kind, new Package($this->package, 'formula')),
                $this->kinds,
            ));
        }

        /**
         * The same two sides every time: jq is installed and no manifest lists it.
         */
        public function sides(DriftItem $item): array
        {
            return ['dotfiles' => 'not listed', 'mac' => 'installed 1.7.1'];
        }

        /**
         * The same menu every time, whatever the item.
         */
        public function actionsFor(DriftItem $item): array
        {
            return ['add' => 'Add to dotfiles', 'uninstall' => 'Uninstall', 'install' => 'Install', 'ignore' => 'Ignore on this Mac', 'skip' => 'Skip'];
        }

        /**
         * Add, Install and Uninstall get one step each. Every other action does nothing.
         */
        public function steps(Decision $decision): array
        {
            return match ($decision->action) {
                Action::AddToManifest => [new Step(
                    "Add formula:{$this->package} to ".basename($decision->manifests[0]),
                    fn () => app(ManifestEditor::class)->edit($decision->manifests[0], fn (string $contents) => $contents."brew \"jq\"\n"),
                )],
                Action::Install => [new Step("brew install --formula {$this->package}", function () {
                    $this->ran[] = "install {$this->package}";

                    return ['kind' => 'command', 'command' => ['brew', 'install', '--formula', $this->package]];
                })],
                Action::Uninstall => [new Step('brew uninstall --formula jq', function () {
                    if ($this->uninstallError !== null) {
                        throw new RuntimeException($this->uninstallError);
                    }

                    $this->ran[] = 'uninstall jq';

                    return ['kind' => 'command', 'command' => ['brew', 'uninstall', '--formula', 'jq']];
                })],
                default => [],
            };
        }
    };
}

/**
 * A Mac with one Homebrew, an empty Brewfile in a saved repo, and no login shell to read. Sets
 * $test->dir and $test->settings; the caller's afterEach removes the directory.
 */
function fakeMac(TestCase $test): void
{
    $test->dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    mkdir("{$test->dir}/repo", 0755, true);
    mkdir("{$test->dir}/brew/bin", 0755, true);
    touch("{$test->dir}/repo/Brewfile");
    file_put_contents("{$test->dir}/brew/bin/brew", "#!/bin/sh\n");
    chmod("{$test->dir}/brew/bin/brew", 0755);

    $settings = new UserSettings("{$test->dir}/config.json");
    $settings->set('homebrew.prefix', "{$test->dir}/brew");
    $shell = (new FakeShell)
        ->expect(['/usr/sbin/scutil', '--get', 'LocalHostName'], "mac\n")
        ->expect(['/bin/zsh', '+m', '-lic', 'printf __DOTSNITCH_ENV__; /usr/bin/env -0'], exitCode: 1);
    $inherited = ['SHELL' => '/bin/zsh', 'HOME' => $test->dir, 'PATH' => "{$test->dir}/brew/bin"];
    $settings->set('repo', realpath("{$test->dir}/repo"));
    $test->settings = $settings;
    app()->instance(UserSettings::class, $settings);
    app()->instance(PathResolver::class, new PathResolver($shell, $settings, $inherited));
    app()->instance(Machine::class, new Machine($shell));
    app()->instance(ManifestEditor::class, new ManifestEditor("{$test->dir}/backups"));
    app()->instance(ReportStore::class, new ReportStore("{$test->dir}/state"));
    app()->instance(SyncLog::class, new SyncLog("{$test->dir}/state"));
    app()->instance(StatusLock::class, new StatusLock("{$test->dir}/state/".StatusLock::FILE));
}

/**
 * Throws PHP warnings as ErrorException unless @ silenced them, the way Laravel does once the app
 * has booted. Unit tests never boot it. Call restore_error_handler() when done.
 */
function throwWarnings(): void
{
    set_error_handler(fn (int $level, string $message) => (error_reporting() & $level) === 0 ?: throw new ErrorException($message));
}

/**
 * Registers the fake as the only check besides a broken Homebrew, and returns it.
 */
function onlyOtherCheck(): SyncTarget
{
    $other = fakeTarget('other', fails: false);
    app()->instance(Homebrew::class, fakeTarget('brew', fails: true));
    app()->instance('fake-target', $other);
    app()->tag(['fake-target'], 'sync-targets');

    return $other;
}
