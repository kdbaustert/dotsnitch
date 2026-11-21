<?php

declare(strict_types=1);

use App\Support\UserSettings;
use Laravel\Prompts\Prompt;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    $this->settings = new UserSettings("{$this->dir}/config.json");
    $this->app->instance(UserSettings::class, $this->settings);
});

afterEach(function () {
    // Same reset as SyncCommandTest: the testing env reaches later tests through Prompts' statics.
    Prompt::interactive(false);
    (new ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('says when nothing is ignored', function () {
    $this->artisan('config', ['--ignored' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Nothing is ignored on this Mac.')
        ->assertExitCode(0);
});

it('lists ignores by check label without a terminal and changes nothing', function () {
    $this->settings->set('ignore', ['brew' => ['cask:docker'], 'symlinks' => ['/Users/me/My Notes']]);

    $this->artisan('config', ['--ignored' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Symlinked config files │ /Users/me/My Notes')
        ->assertExitCode(0);

    expect($this->settings->get('ignore.brew'))->toBe(['cask:docker']);
});

it('stops ignoring what you pick', function () {
    $this->settings->set('ignore', ['brew' => ['cask:docker', 'formula:jq'], 'symlinks' => ['/Users/me/My Notes']]);
    $this->app['env'] = 'testing';

    $this->artisan('config', ['--ignored' => true])
        ->expectsQuestion('Stop ignoring which of these?', ['brew formula:jq', 'symlinks /Users/me/My Notes'])
        ->expectsOutputToContain('Stopped ignoring 2.')
        ->assertExitCode(0);

    expect($this->settings->get('ignore'))->toBe(['brew' => ['cask:docker'], 'symlinks' => []]);
});
