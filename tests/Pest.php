<?php

declare(strict_types=1);

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
use App\Targets\Homebrew;
use Tests\Fakes\FakeShell;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

/**
 * A check that reads any Brewfile and either throws or reports one untracked package. Adding it
 * appends a line through the real ManifestEditor; uninstalling only records the call in $ran.
 */
function fakeTarget(string $key, bool $fails, bool $available = true): SyncTarget
{
    return new class($key, $fails, $available) implements SyncTarget
    {
        /**
         * @param  bool  $fails  true makes scan() throw
         */
        public function __construct(private readonly string $key, private readonly bool $fails, private readonly bool $available) {}

        /**
         * Whatever key the test passed in.
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
         * False only when the test wants a check that isn't installed.
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

            return new DriftReport($this->key, [new DriftItem($this->key, DriftKind::Untracked, new Package('jq', 'formula'))]);
        }

        /**
         * The same menu every time, whatever the item.
         */
        public function actionsFor(DriftItem $item): array
        {
            return ['add' => 'Add to dotfiles', 'uninstall' => 'Uninstall', 'ignore' => 'Ignore on this Mac', 'skip' => 'Skip'];
        }

        /**
         * Add and Uninstall get one step each. Every other action does nothing.
         */
        public function steps(Decision $decision): array
        {
            return match ($decision->action) {
                Action::AddToManifest => [new Step('Add formula:jq to '.basename($decision->manifests[0]), function () use ($decision) {
                    app(ManifestEditor::class)->edit($decision->manifests[0], fn (string $contents) => $contents."brew \"jq\"\n");
                })],
                Action::Uninstall => [new Step('brew uninstall --formula jq', function () {
                    $this->ran[] = 'uninstall jq';
                })],
                default => [],
            };
        }

        /** @var list<string> */
        public array $ran = [];

        /** @var list<string> */
        public array $patterns = ['Brewfile'];
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
    app()->instance(StatusLock::class, new StatusLock("{$test->dir}/state/".StatusLock::FILE));
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
