<?php

declare(strict_types=1);

use App\Data\Package;
use App\Manifests\BrewfileFormat;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use App\Support\UserSettings;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\DriftReport;
use App\Sync\Step;
use App\Targets\Homebrew;
use Tests\Fakes\FakeShell;

const BREWFILE = '/repo/Brewfile';

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    mkdir("{$this->dir}/brew/bin", 0755, true);
    $this->brew = "{$this->dir}/brew/bin/brew";
    file_put_contents($this->brew, "#!/bin/sh\n");
    chmod($this->brew, 0755);

    $settings = new UserSettings("{$this->dir}/config.json");
    $settings->set('homebrew.prefix', "{$this->dir}/brew");
    $this->shell = new FakeShell;
    $this->homebrew = new Homebrew(
        $this->shell,
        new PathResolver($this->shell, $settings, []),
        new ManifestEditor("{$this->dir}/backups"),
    );
    $this->log = [];
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * Makes `brew bundle list` print these names for the Brewfile.
 *
 * @param  array{tap?: list<string>, formula?: list<string>, cask?: list<string>}  $entries
 */
function brewfileLists(array $entries): void
{
    foreach (['tap', 'formula', 'cask'] as $type) {
        $names = $entries[$type] ?? [];
        test()->shell->expect([test()->brew, 'bundle', 'list', '--file='.BREWFILE, "--{$type}"], implode("\n", $names)."\n");
    }
}

/**
 * The installed fixture and the tap list it goes with.
 */
function installedBrew(FakeShell $shell, string $brew): void
{
    $shell->expect([$brew, 'info', '--json=v2', '--installed'], file_get_contents(__DIR__.'/../Fixtures/brew/info-installed.json'));
    $shell->expect([$brew, 'tap'], "fif7y/tap\nversenilvis/iris\n");
}

/**
 * Scans a Brewfile that has at least one item of every drift kind.
 *
 * @param  callable(string): void  $log
 */
function scanEveryKind(callable $log): DriftReport
{
    brewfileLists([
        'formula' => ['svn', 'youtube-dl', 'iris', 'gone-formula', 'mpv'],
        'cask' => ['old-app', 'xquartz-helper', 'superduper'],
    ]);
    installedBrew(test()->shell, test()->brew);
    test()->shell
        ->expect([test()->brew, 'info', '--json=v2', '--formula', 'gone-formula', 'mpv'], exitCode: 1,
            errorOutput: "Error: No available formula with the name \"gone-formula\".\n")
        ->expect([test()->brew, 'info', '--json=v2', '--formula', 'mpv'], file_get_contents(__DIR__.'/../Fixtures/brew/info-missing-formulae.json'))
        ->expect([test()->brew, 'info', '--json=v2', '--cask', 'superduper'], file_get_contents(__DIR__.'/../Fixtures/brew/info-missing-casks.json'));

    return test()->homebrew->scan([BREWFILE], $log);
}

/**
 * The item for a package key such as "formula:mpv", in the given drift kind.
 */
function itemOf(DriftReport $report, string $kindAndPackage): DriftItem
{
    $key = "{$report->check}:{$kindAndPackage}";

    return collect($report->items)->first(fn (DriftItem $item) => $item->key() === $key)
        ?? throw new LogicException("No drift item {$key}");
}

/**
 * @param  list<Step>  $steps
 * @return list<string>
 */
function descriptionsOf(array $steps): array
{
    return array_map(fn (Step $step) => $step->description, $steps);
}

/**
 * @return list<array{string, string, ?string}> kind, package key and detail
 */
function driftOf(array $items): array
{
    return array_map(fn (DriftItem $item) => [$item->kind->value, $item->subject->key(), $item->detail], $items);
}

it('reports missing, untracked, deprecated and disabled entries', function () {
    $report = scanEveryKind(function (string $line) {
        $this->log[] = $line;
    });

    expect($report->check)->toBe('brew')
        ->and(driftOf($report->items))->toBe([
            ['missing', 'formula:gone-formula', "Homebrew doesn't know this name"],
            ['missing', 'formula:mpv', null],
            ['missing', 'cask:superduper', null],
            ['untracked', 'formula:jq', null],
            ['untracked', 'cask:zed', null],
            ['untracked', 'tap:fif7y/tap', null],
            ['deprecated', 'formula:youtube-dl', 'deprecated, use `yt-dlp` instead'],
            ['disabled', 'cask:old-app', 'disabled upstream (discontinued)'],
            ['deprecated', 'cask:superduper', 'deprecated upstream (unmaintained)'],
        ])
        ->and($this->log)->toBe([
            'Reading Brewfile',
            'Brewfile lists 8',
            'Listing installed Homebrew packages',
            '12 installed',
            "Checking formulae that aren't installed",
            "Checking casks that aren't installed",
        ]);
});

it('counts dependencies and the taps of listed packages as tracked, and ignores tap case', function () {
    brewfileLists([
        'tap' => ['FIF7Y/tap'],
        'formula' => ['subversion', 'jq', 'versenilvis/iris/iris', 'youtube-dl'],
        'cask' => ['zed', 'old-app', 'xquartz-helper'],
    ]);
    installedBrew($this->shell, $this->brew);

    $report = $this->homebrew->scan([BREWFILE], fn () => null);

    expect(driftOf($report->itemsOfKind(DriftKind::Untracked)))->toBe([])
        ->and(driftOf($report->itemsOfKind(DriftKind::Missing)))->toBe([]);
});

it("skips the deprecation lookup for a tap that isn't tapped", function () {
    brewfileLists(['formula' => ['someone/elsewhere/tool']]);
    installedBrew($this->shell, $this->brew);

    $report = $this->homebrew->scan([BREWFILE], fn () => null);

    expect(driftOf($report->itemsOfKind(DriftKind::Missing)))->toBe([['missing', 'formula:someone/elsewhere/tool', null]])
        ->and(array_column($this->shell->ran(), 2))->not->toContain('--formula');
});

it('logs and moves on when the missing lookup fails for another reason', function () {
    brewfileLists(['cask' => ['superduper']]);
    installedBrew($this->shell, $this->brew);
    $this->shell->expect([$this->brew, 'info', '--json=v2', '--cask', 'superduper'], exitCode: 1, errorOutput: "Error: no network\n");

    $report = $this->homebrew->scan([BREWFILE], function (string $line) {
        $this->log[] = $line;
    });

    expect(driftOf($report->itemsOfKind(DriftKind::Deprecated)))->toBe([])
        ->and($this->log)->toContain("Couldn't check missing casks: Error: no network");
});

it("throws when brew can't parse the Brewfile", function () {
    $this->shell->expect([$this->brew, 'bundle', 'list', '--file='.BREWFILE, '--tap'], exitCode: 1, errorOutput: "Error: Invalid Brewfile: syntax error\n");

    (new BrewfileFormat($this->shell, $this->brew))->read(BREWFILE);
})->throws(RuntimeException::class, "brew couldn't read /repo/Brewfile: Error: Invalid Brewfile: syntax error");

it('runs brew without auto-update or env hints', function () {
    brewfileLists([]);
    installedBrew($this->shell, $this->brew);

    $this->homebrew->scan([BREWFILE], fn () => null);

    expect($this->shell->environmentOf([$this->brew, 'info', '--json=v2', '--installed']))->toBe(Homebrew::ENVIRONMENT)
        ->and($this->shell->environmentOf([$this->brew, 'bundle', 'list', '--file='.BREWFILE, '--cask']))->toBe(Homebrew::ENVIRONMENT);
});

it('reports the linked version when several are installed', function () {
    $info = json_decode(file_get_contents(__DIR__.'/../Fixtures/brew/info-installed.json'), true);
    $jq = array_search('jq', array_column($info['formulae'], 'full_name'), true);
    $info['formulae'][$jq]['installed'] = [
        ['version' => '1.6', 'installed_as_dependency' => false, 'installed_on_request' => true, 'runtime_dependencies' => []],
        $info['formulae'][$jq]['installed'][0],
    ];
    $info['formulae'][$jq]['linked_keg'] = '1.6';
    brewfileLists([]);
    $this->shell
        ->expect([$this->brew, 'info', '--json=v2', '--installed'], json_encode($info))
        ->expect([$this->brew, 'tap'], '');

    $untracked = $this->homebrew->scan([BREWFILE], fn () => null)->itemsOfKind(DriftKind::Untracked);
    $jqItem = array_values(array_filter($untracked, fn (DriftItem $item) => $item->subject->key() === 'formula:jq'))[0];

    expect($jqItem->subject->version)->toBe('1.6');
});

it("throws when a missing lookup succeeds but isn't JSON", function () {
    brewfileLists(['cask' => ['superduper']]);
    installedBrew($this->shell, $this->brew);
    $this->shell->expect([$this->brew, 'info', '--json=v2', '--cask', 'superduper'], "Warning: something\n");

    $this->homebrew->scan([BREWFILE], fn () => null);
})->throws(RuntimeException::class, "brew info didn't return the expected JSON");

it('offers actions that fit each kind of drift', function () {
    $report = scanEveryKind(fn () => null);
    $actionsOf = fn (string $item) => $this->homebrew->actionsFor(itemOf($report, $item));

    expect($actionsOf('missing:formula:mpv'))->toBe([
        'install' => 'Install', 'remove' => 'Remove from dotfiles', 'ignore' => 'Ignore on this Mac', 'skip' => 'Skip',
    ])
        ->and($actionsOf('missing:formula:gone-formula'))->toBe([
            'remove' => 'Remove from dotfiles', 'ignore' => 'Ignore on this Mac', 'skip' => 'Skip',
        ])
        ->and($actionsOf('untracked:cask:zed'))->toBe([
            'add' => 'Add to dotfiles', 'uninstall' => 'Uninstall', 'ignore' => 'Ignore on this Mac', 'skip' => 'Skip',
        ])
        ->and($actionsOf('deprecated:formula:youtube-dl'))->toBe([
            'replace' => 'Replace with yt-dlp', 'remove' => 'Remove from dotfiles', 'ignore' => 'Keep (ignore on this Mac)', 'skip' => 'Skip',
        ])
        ->and($actionsOf('disabled:cask:old-app'))->toBe([
            'remove' => 'Remove from dotfiles', 'ignore' => 'Keep (ignore on this Mac)', 'skip' => 'Skip',
        ]);
});

it('installs and uninstalls with the right brew command and environment', function () {
    $report = scanEveryKind(fn () => null);
    $stepsFor = fn (string $item, Action $action) => $this->homebrew->steps(new Decision(itemOf($report, $item), $action));
    $tap = new DriftItem('brew', DriftKind::Untracked, new Package('fif7y/tap', 'tap'));

    $steps = [
        ...$stepsFor('missing:formula:mpv', Action::Install),
        ...$stepsFor('untracked:cask:zed', Action::Uninstall),
        ...$this->homebrew->steps(new Decision($tap, Action::Uninstall)),
    ];

    expect(descriptionsOf($steps))->toBe([
        "{$this->brew} install --formula mpv",
        "{$this->brew} uninstall --cask zed",
        "{$this->brew} untap fif7y/tap",
    ])
        ->and($stepsFor('untracked:cask:zed', Action::Ignore))->toBe([])
        ->and($stepsFor('untracked:cask:zed', Action::Skip))->toBe([]);

    $this->shell->expect([$this->brew, 'install', '--formula', 'mpv']);
    ($steps[0]->run)();

    expect($this->shell->environmentOf([$this->brew, 'install', '--formula', 'mpv']))->toBe(Homebrew::ENVIRONMENT);
});

it('throws brew stderr when a command fails', function () {
    $report = scanEveryKind(fn () => null);
    $this->shell->expect([$this->brew, 'install', '--formula', 'mpv'], exitCode: 1, errorOutput: "Error: mpv: no bottle\n");
    [$install] = $this->homebrew->steps(new Decision(itemOf($report, 'missing:formula:mpv'), Action::Install));

    ($install->run)();
})->throws(RuntimeException::class, 'Error: mpv: no bottle');

it('replaces by installing the successor, uninstalling the old one, then swapping the line', function () {
    $report = scanEveryKind(fn () => null);
    $shared = "{$this->dir}/Brewfile";
    $overlay = "{$this->dir}/Brewfile.work";
    file_put_contents($shared, "brew \"jq\"\nbrew \"youtube-dl\" # old\n");
    file_put_contents($overlay, "brew \"mpv\"\n");
    $this->shell
        ->expect([$this->brew, 'install', '--formula', 'yt-dlp'])
        ->expect([$this->brew, 'uninstall', '--formula', 'youtube-dl']);

    $steps = $this->homebrew->steps(new Decision(itemOf($report, 'deprecated:formula:youtube-dl'), Action::Replace, [$shared, $overlay]));
    array_walk($steps, fn (Step $step) => ($step->run)());

    expect(descriptionsOf($steps))->toBe([
        "{$this->brew} install --formula yt-dlp",
        "{$this->brew} uninstall --formula youtube-dl",
        'Replace formula:youtube-dl with formula:yt-dlp in Brewfile',
    ])
        ->and(file_get_contents($shared))->toBe("brew \"jq\"\nbrew \"yt-dlp\" # old\n")
        ->and(file_get_contents($overlay))->toBe("brew \"mpv\"\n");
});

it('removes an entry spelled with an alias', function () {
    scanEveryKind(fn () => null);
    $brewfile = "{$this->dir}/Brewfile";
    file_put_contents($brewfile, "brew \"jq\"\nbrew \"svn\"\n");
    $item = new DriftItem('brew', DriftKind::Missing, new Package('subversion', 'formula'));

    [$remove] = $this->homebrew->steps(new Decision($item, Action::RemoveFromManifest, [$brewfile]));
    ($remove->run)();

    expect($remove->description)->toBe('Remove formula:subversion from Brewfile')
        ->and(file_get_contents($brewfile))->toBe("brew \"jq\"\n");
});

it('adds an untracked package to the chosen manifest', function () {
    $report = scanEveryKind(fn () => null);
    $overlay = "{$this->dir}/Brewfile.work";

    [$add] = $this->homebrew->steps(new Decision(itemOf($report, 'untracked:formula:jq'), Action::AddToManifest, [$overlay]));
    ($add->run)();

    expect($add->description)->toBe('Add formula:jq to Brewfile.work')
        ->and(file_get_contents($overlay))->toBe("brew \"jq\"\n");
});

it('throws when no manifest has a line it can edit', function () {
    $report = scanEveryKind(fn () => null);
    $brewfile = "{$this->dir}/Brewfile";
    file_put_contents($brewfile, "[\"mpv\"].each { |name| brew name }\n");

    $this->homebrew->steps(new Decision(itemOf($report, 'missing:formula:mpv'), Action::RemoveFromManifest, [$brewfile]));
})->throws(RuntimeException::class, "mpv isn't on a line dotsnitch can edit in Brewfile. Edit it by hand.");
