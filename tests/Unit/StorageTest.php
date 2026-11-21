<?php

declare(strict_types=1);

use App\Data\Link;
use App\Data\Package;
use App\Support\UserSettings;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\DriftReport;
use App\Sync\ReportStore;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('creates the settings file on first save and reads it back', function () {
    $path = "{$this->dir}/config/dotsnitch/config.json";

    expect(file_exists($path))->toBeFalse();

    (new UserSettings($path))->set('ignore.brew', ['cask:docker']);

    expect((new UserSettings($path))->get('ignore.brew'))->toBe(['cask:docker'])
        ->and(json_decode(file_get_contents($path), true))->toBe(['ignore' => ['brew' => ['cask:docker']]]);
});

it('returns the default for a setting that was never saved', function () {
    expect((new UserSettings("{$this->dir}/missing.json"))->get('repo', '~/dotfiles'))->toBe('~/dotfiles');
});

it('refuses a settings file that is not valid JSON', function () {
    mkdir($this->dir);
    file_put_contents("{$this->dir}/config.json", '{not json');

    new UserSettings("{$this->dir}/config.json");
})->throws(RuntimeException::class, 'Invalid JSON in');

it('saves drift item keys per check and reads them back', function () {
    $store = new ReportStore($this->dir);

    expect($store->latest())->toBeNull();

    $saved = $store->save('/Users/me/dotfiles', [
        new DriftReport('brew', [new DriftItem('brew', DriftKind::Untracked, new Package('jq', 'formula', '1.7'))]),
        new DriftReport('symlinks', [new DriftItem('symlinks', DriftKind::Broken, new Link('/r/zshrc', '/Users/me/.zshrc'))]),
        new DriftReport('npm'),
    ]);

    expect($store->latest())->toEqual($saved)
        ->and($saved->repo)->toBe('/Users/me/dotfiles')
        ->and($saved->checks)->toBe([
            'brew' => ['brew:untracked:formula:jq'],
            'symlinks' => ['symlinks:broken:/Users/me/.zshrc'],
            'npm' => [],
        ])
        ->and($saved->new)->toBe([])
        ->and($saved->since)->toBeNull()
        ->and($saved->count())->toBe(2);
});

it('marks items the last scan lacked as new, only in checks it also ran', function () {
    mkdir($this->dir);
    file_put_contents("{$this->dir}/last-report.json", json_encode([
        'scanned_at' => '2026-11-20T09:00:00-05:00',
        'repo' => '/Users/me/dotfiles',
        'checks' => ['brew' => ['brew:untracked:formula:jq']],
    ]));
    $store = new ReportStore($this->dir);
    $wget = new DriftItem('brew', DriftKind::Untracked, new Package('wget', 'formula'));
    $reports = [
        new DriftReport('brew', [new DriftItem('brew', DriftKind::Untracked, new Package('jq', 'formula')), $wget]),
        new DriftReport('npm', [new DriftItem('npm', DriftKind::Missing, new Package('typescript', 'npm'))]),
    ];

    $saved = $store->save('/Users/me/dotfiles', $reports);
    // The rescan after changes compares with the same last scan, not with the save just above.
    $rescanned = $store->save('/Users/me/dotfiles', $reports);

    expect($saved->new)->toBe(['brew' => ['brew:untracked:formula:wget']])
        ->and($saved->isNew($wget))->toBeTrue()
        ->and($saved->newCount())->toBe(1)
        ->and($saved->since?->format(DATE_ATOM))->toBe('2026-11-20T09:00:00-05:00')
        ->and($rescanned->new)->toBe($saved->new)
        ->and($store->latest()->new)->toBe($saved->new);
});

it('marks nothing new when the last scan was of another repo', function () {
    mkdir($this->dir);
    file_put_contents("{$this->dir}/last-report.json", json_encode([
        'scanned_at' => '2026-11-20T09:00:00-05:00',
        'repo' => '/Users/me/old-dotfiles',
        'checks' => ['brew' => []],
    ]));

    $saved = (new ReportStore($this->dir))->save('/Users/me/dotfiles', [
        new DriftReport('brew', [new DriftItem('brew', DriftKind::Untracked, new Package('jq', 'formula'))]),
    ]);

    expect($saved->new)->toBe([])
        ->and($saved->since)->toBeNull();
});

it('keeps what the last scan had for a check that failed this time', function () {
    mkdir($this->dir);
    file_put_contents("{$this->dir}/last-report.json", json_encode([
        'scanned_at' => '2026-11-20T09:00:00-05:00',
        'repo' => '/Users/me/dotfiles',
        'checks' => ['brew' => ['brew:untracked:formula:jq'], 'npm' => []],
    ]));

    $saved = (new ReportStore($this->dir))->save('/Users/me/dotfiles', [
        'brew' => new RuntimeException('brew info timed out'),
        'npm' => new DriftReport('npm'),
        'gh' => new RuntimeException('gh is not signed in'),
    ]);

    expect($saved->checks)->toBe(['brew' => ['brew:untracked:formula:jq'], 'npm' => []])
        ->and($saved->new)->toBe(['npm' => []])
        ->and($saved->count())->toBe(1);
});

it('replaces a report it cannot read, with nothing new', function (string $contents) {
    mkdir($this->dir);
    file_put_contents("{$this->dir}/last-report.json", $contents);
    $store = new ReportStore($this->dir);

    expect($store->readableLatest())->toBeNull();

    $saved = $store->save('/Users/me/dotfiles', [new DriftReport('brew', [new DriftItem('brew', DriftKind::Untracked, new Package('jq', 'formula'))])]);

    expect($saved->new)->toBe([])
        ->and($store->latest())->toEqual($saved);
})->with([
    'not JSON' => ['{not json'],
    'no repo' => ['{"scanned_at": "2026-11-20T09:00:00-05:00"}'],
    'bad time' => ['{"scanned_at": "last tuesday-ish", "repo": "/r"}'],
]);

it('keeps what the last --notify saw, and treats a broken file as none', function () {
    $store = new ReportStore($this->dir);

    expect($store->notified())->toBeNull();

    $store->saveNotified(['brew' => ['brew:untracked:formula:jq'], 'npm' => []]);

    expect($store->notified())->toBe(['brew' => ['brew:untracked:formula:jq'], 'npm' => []]);

    file_put_contents("{$this->dir}/last-notified.json", '{not json');

    expect($store->notified())->toBeNull();
});

it('keeps the last 30 scans in reports/', function () {
    mkdir("{$this->dir}/reports", 0755, true);

    foreach (range(1, 30) as $day) {
        touch(sprintf('%s/reports/2025-10-%02dT090000.json', $this->dir, $day));
    }

    (new ReportStore($this->dir))->save('/Users/me/dotfiles', [new DriftReport('npm')]);
    $kept = array_map(basename(...), glob("{$this->dir}/reports/*.json"));

    expect($kept)->toHaveCount(30)
        ->and($kept[0])->toBe('2025-10-02T090000.json')
        ->and($kept[29])->toStartWith(date('Y-m-d'));
});

it('puts reports under XDG_STATE_HOME, else ~/.local/state', function () {
    expect(ReportStore::defaultDirectory(['XDG_STATE_HOME' => '/state', 'HOME' => '/Users/me']))->toBe('/state/dotsnitch')
        ->and(ReportStore::defaultDirectory(['XDG_STATE_HOME' => '', 'HOME' => '/Users/me']))->toBe('/Users/me/.local/state/dotsnitch');
});

it('treats an empty settings file as no settings', function () {
    mkdir($this->dir);
    file_put_contents("{$this->dir}/config.json", "\n");

    expect((new UserSettings("{$this->dir}/config.json"))->get('repo'))->toBeNull();
});

it('writes through a symlinked settings file instead of replacing the link', function () {
    mkdir("{$this->dir}/dotfiles", 0755, true);
    symlink("{$this->dir}/dotfiles/dotsnitch.json", "{$this->dir}/config.json");

    (new UserSettings("{$this->dir}/config.json"))->set('repo', '/r');

    expect(is_link("{$this->dir}/config.json"))->toBeTrue()
        ->and(json_decode(file_get_contents("{$this->dir}/dotfiles/dotsnitch.json"), true))->toBe(['repo' => '/r']);
});
