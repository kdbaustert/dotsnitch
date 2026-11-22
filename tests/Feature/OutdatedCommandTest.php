<?php

declare(strict_types=1);

use App\Contracts\ListsOutdated;
use App\Data\Package;
use App\Targets\Composer;
use App\Targets\Homebrew;
use App\Targets\Npm;
use Illuminate\Support\Facades\Artisan;

/**
 * As much of a check as `outdated` uses: its label, and what it says is outdated.
 *
 * @param  ?list<array{package: Package, latest: string}>  $outdated  null makes outdated() throw
 */
function outdatedTarget(string $label, ?array $outdated): ListsOutdated
{
    return new class($label, $outdated) implements ListsOutdated
    {
        public function __construct(private readonly string $label, private readonly ?array $outdated) {}

        public function label(): string
        {
            return $this->label;
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function outdated(): array
        {
            return $this->outdated ?? throw new RuntimeException('npm outdated printed no JSON');
        }
    };
}

beforeEach(function () {
    fakeMac($this);
    $this->settings->set('homebrew_prefix', '/opt/homebrew');
});

it('lists what each package manager says is outdated, and carries on past one that fails', function () {
    $this->app->instance(Homebrew::class, outdatedTarget('Brew', [['package' => new Package('jq', 'formula', '1.7'), 'latest' => '1.8.1']]));
    $this->app->instance(Npm::class, outdatedTarget('Npm', null));
    $this->app->instance(Composer::class, outdatedTarget('Composer', []));

    $exit = Artisan::call('outdated');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Npm: npm outdated printed no JSON')
        ->and($output)->toMatch('/Brew\s+│\s+jq\s+│\s+1\.7\s+│\s+1\.8\.1/u');
});

it('says so when nothing is outdated', function () {
    foreach ([Homebrew::class => 'Brew', Npm::class => 'Npm', Composer::class => 'Composer'] as $class => $label) {
        $this->app->instance($class, outdatedTarget($label, []));
    }

    $this->artisan('outdated')->expectsOutputToContain('Everything is up to date.')->assertExitCode(0);
});
