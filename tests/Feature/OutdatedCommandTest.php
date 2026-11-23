<?php

declare(strict_types=1);

use App\Contracts\ListsOutdated;
use App\Data\Package;
use App\Targets\Composer;
use App\Targets\Homebrew;
use App\Targets\Npm;
use Illuminate\Support\Facades\Artisan;

/**
 * As much of a check as `outdated` uses: its label, with the label in lowercase for its key, and
 * what it says is outdated.
 *
 * @param  list<array{package: Package, latest: string}>|Throwable|Closure(): list<array{package: Package, latest: string}>  $outdated  outdated() throws a Throwable and calls a Closure
 */
function outdatedTarget(string $label, array|Throwable|Closure $outdated, bool $available = true): ListsOutdated
{
    return new class($label, $outdated, $available) implements ListsOutdated
    {
        public function __construct(
            private readonly string $label,
            private readonly array|Throwable|Closure $outdated,
            private readonly bool $available,
        ) {}

        public function key(): string
        {
            return strtolower($this->label);
        }

        public function label(): string
        {
            return $this->label;
        }

        public function isAvailable(): bool
        {
            return $this->available;
        }

        public function outdated(): array
        {
            return match (true) {
                $this->outdated instanceof Closure => ($this->outdated)(),
                $this->outdated instanceof Throwable => throw $this->outdated,
                default => $this->outdated,
            };
        }
    };
}

beforeEach(function () {
    fakeMac($this);
    $this->settings->set('homebrew_prefix', '/opt/homebrew');
});

it('lists what each package manager says is outdated, and carries on past one that fails', function () {
    $this->app->instance(Homebrew::class, outdatedTarget('Brew', [['package' => new Package('jq', 'formula', '1.7'), 'latest' => '1.8.1']]));
    $this->app->instance(Npm::class, outdatedTarget('Npm', new RuntimeException('npm outdated printed no JSON')));
    $this->app->instance(Composer::class, outdatedTarget('Composer', []));

    $exit = Artisan::call('outdated');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Npm: npm outdated printed no JSON')
        ->and($output)->toMatch('/Brew\s+│\s+jq\s+│\s+1\.7\s+│\s+1\.8\.1/u');
});

it('carries on past a list it can\'t read', function () {
    $this->app->instance(Homebrew::class, outdatedTarget('Brew', [['package' => new Package('jq', 'formula', '1.7'), 'latest' => '1.8.1']]));
    // What Laravel throws for the warning when an entry has no latest version.
    $this->app->instance(Npm::class, outdatedTarget('Npm', new ErrorException('Undefined array key "latest"')));
    $this->app->instance(Composer::class, outdatedTarget('Composer', []));

    $exit = Artisan::call('outdated');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Npm: Undefined array key "latest"')
        ->and($output)->toMatch('/Brew\s+│\s+jq\s+│\s+1\.7\s+│\s+1\.8\.1/u');
});

it('says so when nothing is outdated', function () {
    foreach ([Homebrew::class => 'Brew', Npm::class => 'Npm', Composer::class => 'Composer'] as $class => $label) {
        $this->app->instance($class, outdatedTarget($label, []));
    }

    $this->artisan('outdated')->expectsOutputToContain('Everything is up to date.')->assertExitCode(0);
});

it('asks the package managers side by side', function () {
    $asked = [];
    // Each stops partway, the way ProcessShell does while a command runs.
    $ask = function (string $name) use (&$asked) {
        return function () use (&$asked, $name) {
            $asked[] = "{$name} asked";
            Fiber::suspend();
            $asked[] = "{$name} answered";

            return [];
        };
    };
    $this->app->instance(Homebrew::class, outdatedTarget('Brew', $ask('brew')));
    $this->app->instance(Npm::class, outdatedTarget('Npm', $ask('npm')));
    $this->app->instance(Composer::class, outdatedTarget('Composer', []));

    expect(Artisan::call('outdated'))->toBe(0)
        ->and($asked)->toBe(['brew asked', 'npm asked', 'brew answered', 'npm answered']);
});

it('asks only the package managers --checks names', function () {
    $this->app->instance(Homebrew::class, outdatedTarget('Brew', [['package' => new Package('jq', 'formula', '1.7'), 'latest' => '1.8.1']]));
    $this->app->instance(Npm::class, outdatedTarget('Npm', new RuntimeException('npm outdated printed no JSON')));
    $this->app->instance(Composer::class, outdatedTarget('Composer', []));

    $exit = Artisan::call('outdated', ['--checks' => 'brew, composer']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('jq')->not->toContain('npm outdated');
});

it('rejects a --checks it can\'t ask', function (string $checks, string $message) {
    $this->app->instance(Homebrew::class, outdatedTarget('Brew', []));
    $this->app->instance(Npm::class, outdatedTarget('Npm', [], available: false));

    expect(fn () => Artisan::call('outdated', ['--checks' => $checks]))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'an unknown key' => ['brew,nope', 'Unknown check "nope" in --checks. Checks: brew, mas, composer, npm, pnpm, gh, pipx, uv, cargo.'],
    'no key' => [' , ', '--checks needs at least one check.'],
    'one that isn\'t installed' => ['npm', "Npm isn't installed on this Mac."],
]);

it('prints the list as JSON and nothing else with --json, errors included', function () {
    $this->app->instance(Homebrew::class, outdatedTarget('Brew', [['package' => new Package('jq', 'formula', '1.7'), 'latest' => '1.8.1']]));
    $this->app->instance(Npm::class, outdatedTarget('Npm', new RuntimeException('npm outdated printed no JSON')));
    $this->app->instance(Composer::class, outdatedTarget('Composer', []));

    $exit = Artisan::call('outdated', ['--json' => true]);
    $list = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($list)->toBe(['checks' => [
            'brew' => ['label' => 'Brew', 'error' => null, 'packages' => [['item' => 'formula:jq', 'installed' => '1.7', 'latest' => '1.8.1']]],
            'composer' => ['label' => 'Composer', 'error' => null, 'packages' => []],
            'npm' => ['label' => 'Npm', 'error' => 'npm outdated printed no JSON', 'packages' => []],
        ]]);
});
