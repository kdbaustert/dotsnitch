<?php

declare(strict_types=1);

use App\Data\Package;
use App\Support\JsonFile;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\Step;
use App\Sync\SyncLog;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    $this->install = new Decision(new DriftItem('brew', DriftKind::Missing, new Package('mpv', 'formula')), Action::Install);
    $this->step = new Step('brew install --formula mpv', fn () => []);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('saves the same file again after every step, so a run that dies keeps what it got done', function () {
    $log = new SyncLog($this->dir);
    $effect = ['kind' => 'command', 'command' => ['brew', 'install', '--formula', 'mpv']];
    $ran = ['item' => 'brew:missing:formula:mpv', 'action' => 'install', 'description' => 'brew install --formula mpv', 'effect' => $effect];

    $log->ran($this->install, $this->step, $effect);
    [$file] = glob("{$this->dir}/syncs/*.json");
    $afterOne = JsonFile::read($file);
    $log->failed($this->install, $this->step, 'Error: mpv: no bottle');

    expect($afterOne['steps'])->toBe([$ran])
        ->and(glob("{$this->dir}/syncs/*.json"))->toBe([$file])
        ->and(JsonFile::read($file))->toBe([
            'started_at' => $afterOne['started_at'],
            'steps' => [$ran, ['item' => 'brew:missing:formula:mpv', 'action' => 'install', 'description' => 'brew install --formula mpv', 'failed' => 'Error: mpv: no bottle']],
            'commit' => null,
        ]);
});

it('keeps the last 30 syncs in syncs/', function () {
    mkdir("{$this->dir}/syncs", 0755, true);

    foreach (range(1, 30) as $day) {
        touch(sprintf('%s/syncs/2025-10-%02dT090000.json', $this->dir, $day));
    }

    (new SyncLog($this->dir))->ran($this->install, $this->step, []);
    $kept = array_map(basename(...), glob("{$this->dir}/syncs/*.json"));

    expect($kept)->toHaveCount(30)
        ->and($kept[0])->toBe('2025-10-02T090000.json')
        ->and($kept[29])->toStartWith(date('Y-m-d'));
});
