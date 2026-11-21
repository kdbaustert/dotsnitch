<?php

declare(strict_types=1);

use App\Data\Link;
use App\Data\Package;
use App\Support\UserSettings;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\DriftReport;
use App\Sync\IgnoreList;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    $this->settings = new UserSettings("{$this->dir}/config.json");
    $this->ignores = new IgnoreList($this->settings);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('saves a subject once under its check', function () {
    $item = new DriftItem('brew', DriftKind::Untracked, new Package('docker', 'cask'));

    $this->ignores->add($item);
    $this->ignores->add($item);

    expect($this->settings->get('ignore'))->toBe(['brew' => ['cask:docker']]);
});

it('hides an ignored subject whichever way it drifted, and counts it', function () {
    $this->settings->set('ignore.brew', ['formula:jq']);
    $report = new DriftReport('brew', [
        new DriftItem('brew', DriftKind::Missing, new Package('jq', 'formula')),
        new DriftItem('brew', DriftKind::Untracked, new Package('wget', 'formula')),
    ]);

    $filtered = $this->ignores->apply($report);

    expect(array_map(fn (DriftItem $item) => $item->key(), $filtered->items))->toBe(['brew:untracked:formula:wget'])
        ->and($filtered->ignored)->toBe(1)
        ->and($this->ignores->apply($filtered)->ignored)->toBe(1);
});

it('only hides subjects ignored for the same check', function () {
    $this->settings->set('ignore.npm', ['npm:jq']);
    $report = new DriftReport('symlinks', [new DriftItem('symlinks', DriftKind::NotLinked, new Link('/repo/.zshrc', '/home/.zshrc'))]);

    expect($this->ignores->apply($report))->toEqual($report);
});

it('stops ignoring picked keys and lists only checks that still have some', function () {
    $this->settings->set('ignore', ['brew' => ['cask:docker', 'formula:jq'], 'npm' => ['npm:typescript']]);

    $this->ignores->remove('brew', ['formula:jq']);
    $this->ignores->remove('npm', ['npm:typescript']);

    expect($this->ignores->all())->toBe(['brew' => ['cask:docker']]);
});
