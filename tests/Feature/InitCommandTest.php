<?php

declare(strict_types=1);

use Laravel\Prompts\Prompt;

beforeEach(fn () => fakeMac($this));

afterEach(function () {
    // Prompts keeps its config in statics, so the fallback switched on below would reach later tests.
    Prompt::interactive(false);
    (new ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('writes what is installed into a new file for a check with no manifest', function () {
    onlyOtherCheck()->patterns = ['other.txt'];
    app()['env'] = 'testing';

    $this->artisan('init', ['--repo' => "{$this->dir}/repo"])
        ->expectsOutputToContain('other.txt')
        ->expectsConfirmation('Write these files?', 'yes')
        ->expectsOutputToContain('Applied 1 change.')
        ->assertExitCode(0);

    expect(file_get_contents("{$this->dir}/repo/other.txt"))->toBe("brew \"jq\"\n");
});

it('writes nothing when the answer is no', function () {
    onlyOtherCheck()->patterns = ['other.txt'];
    app()['env'] = 'testing';

    $this->artisan('init', ['--repo' => "{$this->dir}/repo"])
        ->expectsConfirmation('Write these files?', 'no')
        ->assertExitCode(0);

    expect(file_exists("{$this->dir}/repo/other.txt"))->toBeFalse();
});

it('only lists the files on a dry run', function () {
    onlyOtherCheck()->patterns = ['other.txt'];

    $this->artisan('init', ['--repo' => "{$this->dir}/repo", '--dry-run' => true, '--no-interaction' => true])
        ->expectsOutputToContain('other.txt')
        ->expectsOutputToContain('Dry run. Nothing was written.')
        ->assertExitCode(0);

    expect(file_exists("{$this->dir}/repo/other.txt"))->toBeFalse();
});

it('writes nothing without a terminal', function () {
    onlyOtherCheck()->patterns = ['other.txt'];

    $this->artisan('init', ['--repo' => "{$this->dir}/repo", '--no-interaction' => true])
        ->expectsOutputToContain('Run it in a terminal to write them.')
        ->assertExitCode(0);

    expect(file_exists("{$this->dir}/repo/other.txt"))->toBeFalse();
});

it('leaves a check that already has a manifest alone', function () {
    onlyOtherCheck();

    $this->artisan('init', ['--repo' => "{$this->dir}/repo", '--no-interaction' => true])
        ->expectsOutputToContain('Every check that can run here already has a manifest.')
        ->assertExitCode(0);

    expect(file_get_contents("{$this->dir}/repo/Brewfile"))->toBe('');
});

it('skips a check named in --checks that has a manifest', function () {
    onlyOtherCheck()->patterns = ['other.txt'];

    $this->artisan('init', ['--repo' => "{$this->dir}/repo", '--checks' => 'brew', '--no-interaction' => true])
        ->expectsOutputToContain('Every check that can run here already has a manifest.')
        ->assertExitCode(0);
});
