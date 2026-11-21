<?php

declare(strict_types=1);

use App\Contracts\Shell;
use App\Support\DotfilesRepo;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use App\Support\UserSettings;
use App\Sync\ReportStore;
use App\Targets\Homebrew;
use App\Targets\Symlinks;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Tests\Fakes\FakeShell;

beforeEach(fn () => fakeMac($this));

/**
 * Prompts skip themselves when stdin isn't a terminal. In the testing env Laravel asks plain
 * console questions instead, which expectsQuestion can answer.
 */
function answerWithQuestions(): void
{
    app()['env'] = 'testing';
}

afterEach(function () {
    // Prompts keeps its config in statics, and fallbackWhen() can only switch fallback on, so the
    // testing env from answerWithQuestions() would reach every later test without this.
    Prompt::interactive(false);
    (new ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('keeps scanning after one check fails and exits with failure', function () {
    $this->app->instance(Homebrew::class, fakeTarget('brew', fails: true));
    $this->app->instance('fake-target', fakeTarget('other', fails: false));
    $this->app->tag(['fake-target'], 'sync-targets');

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'brew,other', '--no-interaction' => true])
        ->expectsOutputToContain('Failed: brew info failed: boom')
        ->expectsOutputToContain('formula:jq')
        ->assertExitCode(1);
});

it('starts from the last picked checks, and only reports without a terminal', function () {
    $this->settings->set('checks', ['other']);
    $this->app->instance(Homebrew::class, fakeTarget('brew', fails: true));
    $this->app->instance('fake-target', fakeTarget('other', fails: false));
    $this->app->tag(['fake-target'], 'sync-targets');

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--no-interaction' => true])
        ->doesntExpectOutputToContain('boom')
        ->expectsOutputToContain('1 untracked')
        ->assertExitCode(1);

    expect($this->settings->get('checks'))->toBe(['other']);
});

it('rejects an unknown check in --checks', function () {
    $this->app->instance(Homebrew::class, fakeTarget('brew', fails: false));

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'brew,nope']);
})->throws(InvalidArgumentException::class, 'Unknown check "nope" in --checks. Checks: brew, mas, composer, npm, pnpm, gh, pipx, uv, cargo, symlinks.');

it("rejects a check in --checks whose tool isn't installed", function () {
    $this->app->instance(Homebrew::class, fakeTarget('brew', fails: false, available: false));

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'brew']);
})->throws(InvalidArgumentException::class, "Brew isn't installed on this Mac.");

it('prints the plan and the commands on a dry run, and changes nothing but the saved scan', function () {
    $other = onlyOtherCheck();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other', '--dry-run' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Add to dotfiles')
        ->expectsOutputToContain('Dry run. These would run, in order:')
        ->expectsOutputToContain('Add formula:jq to Brewfile')
        ->assertExitCode(0);

    expect(file_get_contents("{$this->dir}/repo/Brewfile"))->toBe('')
        ->and($other->ran)->toBe([])
        ->and(app(ReportStore::class)->latest()->checks)->toBe(['other' => ['other:untracked:formula:jq']]);
});

it('adds to a new overlay for this Mac, rescans and saves the report', function () {
    onlyOtherCheck();
    answerWithQuestions();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsQuestion('How do you want to resolve it?', 'review')
        ->expectsQuestion('Pick the untracked Other items to add to dotfiles', ['formula:jq'])
        ->expectsQuestion('Add Other items to which file?', 'Brewfile.mac')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    expect(file_get_contents("{$this->dir}/repo/Brewfile.mac"))->toBe("brew \"jq\"\n")
        ->and(file_get_contents("{$this->dir}/repo/Brewfile"))->toBe('')
        ->and(app(ReportStore::class)->latest()->checks)->toBe(['other' => ['other:untracked:formula:jq']]);
});

it('drops an uninstall when the second confirm says no', function () {
    $other = onlyOtherCheck();
    answerWithQuestions();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsQuestion('How do you want to resolve it?', 'each')
        ->expectsQuestion('Other, untracked: formula:jq', 'uninstall')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->expectsConfirmation('Uninstall formula:jq?', 'no')
        ->assertExitCode(0);

    expect($other->ran)->toBe([]);
});

it('offers to commit the manifests it changed when the repo is a git checkout', function () {
    onlyOtherCheck();
    answerWithQuestions();
    mkdir("{$this->dir}/repo/.git");
    file_put_contents("{$this->dir}/brew/bin/git", "#!/bin/sh\n");
    chmod("{$this->dir}/brew/bin/git", 0755);
    $git = ["{$this->dir}/brew/bin/git", '-C', realpath("{$this->dir}/repo")];
    $shell = (new FakeShell)
        ->expect([...$git, 'diff', '--stat', '--', 'Brewfile'], " Brewfile | 1 +\n")
        ->expect([...$git, 'add', '--', 'Brewfile'])
        ->expect([...$git, 'commit', '-m', 'dotsnitch: add jq', '--', 'Brewfile']);
    $this->app->instance(Shell::class, $shell);

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsQuestion('How do you want to resolve it?', 'review')
        ->expectsQuestion('Pick the untracked Other items to add to dotfiles', ['formula:jq'])
        ->expectsQuestion('Add Other items to which file?', 'Brewfile')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->expectsOutputToContain('Brewfile | 1 +')
        ->expectsConfirmation('Commit these changes to your dotfiles?', 'yes')
        ->assertExitCode(0);

    expect(array_map(fn (array $command) => $command[3], $shell->ran()))->toBe(['diff', 'add', 'commit'])
        ->and(file_get_contents("{$this->dir}/repo/Brewfile"))->toBe("brew \"jq\"\n");
});

it('prints the diff for Show diff and asks again', function () {
    $home = realpath($this->dir);
    $repo = "{$home}/repo";
    file_put_contents("{$repo}/install.conf.yaml", "- link:\n    ~/.zshrc: .zshrc\n");
    file_put_contents("{$repo}/.zshrc", "repo\n");
    file_put_contents("{$home}/.zshrc", "home\n");
    $shell = (new FakeShell)->expect(['/usr/bin/diff', '-ru', "{$home}/.zshrc", "{$repo}/.zshrc"], "-home\n+repo\n", exitCode: 1);
    $this->app->instance(Symlinks::class, new Symlinks(app(DotfilesRepo::class), $shell, app(ManifestEditor::class), ['HOME' => $home]));
    answerWithQuestions();

    $this->artisan('sync', ['--repo' => $repo, '--checks' => 'symlinks'])
        ->expectsQuestion('How do you want to resolve it?', 'each')
        ->expectsQuestion("Symlinked config files, conflict: {$home}/.zshrc", 'diff')
        ->expectsOutputToContain('+repo')
        ->expectsQuestion("Symlinked config files, conflict: {$home}/.zshrc", 'skip')
        ->expectsOutputToContain('Nothing to change.')
        ->assertExitCode(0);

    expect(file_get_contents("{$home}/.zshrc"))->toBe("home\n");
});

it('hides what this Mac ignores and says how many', function () {
    onlyOtherCheck();
    $this->settings->set('ignore.other', ['formula:jq']);

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other', '--no-interaction' => true])
        ->expectsOutputToContain('In sync (1 ignored)')
        ->expectsOutputToContain('1 ignored on this Mac. See them with dotsnitch config --ignored.')
        ->expectsOutputToContain('Everything is in sync.')
        ->assertExitCode(0);
});

it('leaves an item ignored during the run out of the saved report', function () {
    onlyOtherCheck();
    answerWithQuestions();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsQuestion('How do you want to resolve it?', 'each')
        ->expectsQuestion('Other, untracked: formula:jq', 'ignore')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    expect($this->settings->get('ignore.other'))->toBe(['formula:jq'])
        ->and(app(ReportStore::class)->latest()->checks)->toBe(['other' => []]);
});

it('uses the first Homebrew without a terminal and saves nothing', function () {
    onlyOtherCheck();
    mkdir("{$this->dir}/brew2/bin", 0755, true);
    copy("{$this->dir}/brew/bin/brew", "{$this->dir}/brew2/bin/brew");
    chmod("{$this->dir}/brew2/bin/brew", 0755);
    $settings = new UserSettings("{$this->dir}/fresh.json");
    $shell = (new FakeShell)->expect(['/bin/zsh', '+m', '-lic', 'printf __DOTSNITCH_ENV__; /usr/bin/env -0'], exitCode: 1);
    $inherited = ['SHELL' => '/bin/zsh', 'HOME' => $this->dir, 'PATH' => '/usr/bin:/bin'];
    $this->app->instance(UserSettings::class, $settings);
    $this->app->instance(PathResolver::class, new PathResolver($shell, $settings, $inherited, ["{$this->dir}/brew", "{$this->dir}/brew2"]));

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other', '--no-interaction' => true])
        ->expectsOutputToContain("Using the Homebrew in {$this->dir}/brew this run.")
        ->assertExitCode(1);

    expect($settings->get('homebrew'))->toBeNull();
});
