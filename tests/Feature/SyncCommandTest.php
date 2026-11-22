<?php

declare(strict_types=1);

use App\Contracts\Shell;
use App\Support\DotfilesRepo;
use App\Support\JsonFile;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use App\Support\StatusLock;
use App\Support\UserSettings;
use App\Sync\DriftKind;
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

/**
 * The log of the one sync the test applied.
 *
 * @return array<mixed>
 */
function syncLog(): array
{
    [$file] = glob(test()->dir.'/state/syncs/*.json');

    return JsonFile::read($file);
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

it("fails a check whose manifest can't be read, and still runs the others", function () {
    $locked = fakeTarget('other', fails: false);
    $locked->patterns = ['locked.txt'];
    $this->app->instance(Homebrew::class, fakeTarget('brew', fails: false));
    $this->app->instance('fake-target', $locked);
    $this->app->tag(['fake-target'], 'sync-targets');
    file_put_contents("{$this->dir}/repo/locked.txt", "jq\n");
    chmod("{$this->dir}/repo/locked.txt", 0);

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'brew,other', '--no-interaction' => true])
        ->expectsOutputToContain('Failed to open stream: Permission denied')
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

it('skips anything in the saved checks that isn\'t a check key', function () {
    onlyOtherCheck();
    $this->settings->set('checks', [['other'], null, 'other']);

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--no-interaction' => true])
        ->doesntExpectOutputToContain('boom')
        ->expectsOutputToContain('1 untracked')
        ->assertExitCode(1);
});

it('says under each package what the dotfiles and this Mac have', function () {
    onlyOtherCheck();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other', '--no-interaction' => true])
        ->expectsOutputToContain('untracked  formula:jq')
        ->expectsOutputToContain('dotfiles  not listed')
        ->expectsOutputToContain('this Mac  installed 1.7.1')
        ->assertExitCode(1);
});

it('holds the status lock, so a status run waits for the sync to finish', function () {
    onlyOtherCheck();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other', '--no-interaction' => true])
        ->assertExitCode(1);

    expect((new StatusLock("{$this->dir}/state/".StatusLock::FILE))->isHeld())->toBeTrue();
});

it('rejects an unknown check in --checks', function () {
    $this->app->instance(Homebrew::class, fakeTarget('brew', fails: false));

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'brew,nope']);
})->throws(InvalidArgumentException::class, 'Unknown check "nope" in --checks. Checks: brew, mas, composer, npm, pnpm, gh, pipx, uv, cargo, mise, vscode, defaults, symlinks, repo.');

it('rejects --checks with no check in it', function () {
    $this->app->instance(Homebrew::class, fakeTarget('brew', fails: false));

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => ',']);
})->throws(InvalidArgumentException::class, '--checks needs at least one check. Checks: brew, mas, composer, npm, pnpm, gh, pipx, uv, cargo, mise, vscode, defaults, symlinks, repo.');

it("rejects a check in --checks whose tool isn't installed", function () {
    $this->app->instance(Homebrew::class, fakeTarget('brew', fails: false, available: false));

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'brew']);
})->throws(InvalidArgumentException::class, "Brew isn't installed on this Mac.");

it('lists a step with a name that looks like a color tag as it is', function () {
    onlyOtherCheck()->package = 'a<info>b';

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other', '--dry-run' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Add formula:a<info>b to Brewfile')
        ->assertExitCode(0);
});

it('prints the plan and the commands on a dry run, and changes nothing but the saved scan', function () {
    $other = onlyOtherCheck();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other', '--dry-run' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Add to dotfiles')
        ->expectsOutputToContain('Dry run. These would run, in order:')
        ->expectsOutputToContain('Add formula:jq to Brewfile')
        ->assertExitCode(0);

    expect(file_get_contents("{$this->dir}/repo/Brewfile"))->toBe('')
        ->and($other->ran)->toBe([])
        ->and(app(ReportStore::class)->latest()->checks)->toBe(['other' => ['other:untracked:formula:jq']])
        ->and(file_exists("{$this->dir}/state/syncs"))->toBeFalse();
});

it('adds to a new overlay for this Mac, rescans and saves the report', function () {
    onlyOtherCheck();
    answerWithQuestions();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsQuestion('How do you want to resolve it?', 'review')
        ->expectsQuestion('Other, untracked: pick the ones to add to dotfiles', ['formula:jq'])
        ->expectsQuestion('Add Other items to which file?', 'Brewfile.mac')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    expect(file_get_contents("{$this->dir}/repo/Brewfile.mac"))->toBe("brew \"jq\"\n")
        ->and(file_get_contents("{$this->dir}/repo/Brewfile"))->toBe('')
        ->and(app(ReportStore::class)->latest()->checks)->toBe(['other' => ['other:untracked:formula:jq']]);
});

it('adds to a setup script without offering an overlay, since a new one has no array to add to', function () {
    onlyOtherCheck()->patterns = ['mas.sh'];
    file_put_contents("{$this->dir}/repo/mas.sh", "apps=(\n)\n");
    answerWithQuestions();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsQuestion('How do you want to resolve it?', 'review')
        ->expectsQuestion('Other, untracked: pick the ones to add to dotfiles', ['formula:jq'])
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    expect(file_get_contents("{$this->dir}/repo/mas.sh"))->toBe("apps=(\n)\nbrew \"jq\"\n")
        ->and(file_exists("{$this->dir}/repo/mas.sh.mac"))->toBeFalse();
});

it('drops an uninstall when its own confirm says no, before it lists any commands', function () {
    $other = onlyOtherCheck();
    answerWithQuestions();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsQuestion('How do you want to resolve it?', 'each')
        ->expectsQuestion('Other, untracked: formula:jq', 'uninstall')
        ->expectsConfirmation('Uninstall formula:jq?', 'no')
        ->expectsOutputToContain('Nothing to change.')
        ->doesntExpectOutputToContain('These will run, in order:')
        ->doesntExpectOutputToContain('Applied')
        ->assertExitCode(0);

    expect($other->ran)->toBe([]);
});

it('calls a listed package this Mac lacks "not installed" in the summary and the question', function () {
    onlyOtherCheck()->kinds = [DriftKind::Missing];
    answerWithQuestions();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsOutputToContain('1 not installed')
        ->expectsQuestion('How do you want to resolve it?', 'each')
        ->expectsQuestion('Other, not installed: formula:jq', 'skip')
        ->assertExitCode(0);
});

it('keeps a choice for a package when its other item is skipped', function () {
    onlyOtherCheck()->kinds = [DriftKind::Untracked, DriftKind::Deprecated];
    answerWithQuestions();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsQuestion('How do you want to resolve it?', 'each')
        ->expectsQuestion('Other, untracked: formula:jq', 'add')
        ->expectsQuestion('Other, deprecated: formula:jq', 'skip')
        ->expectsQuestion('Add Other items to which file?', 'Brewfile')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    expect(file_get_contents("{$this->dir}/repo/Brewfile"))->toBe("brew \"jq\"\n");
});

it('shows the commands before it asks, then how many it applied and where the backups and record are', function () {
    onlyOtherCheck();
    file_put_contents("{$this->dir}/repo/Brewfile", "brew \"mpv\"\n");
    answerWithQuestions();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsQuestion('How do you want to resolve it?', 'each')
        ->expectsQuestion('Other, untracked: formula:jq', 'add')
        ->expectsQuestion('Add Other items to which file?', 'Brewfile')
        ->expectsOutputToContain('These will run, in order:')
        ->expectsOutputToContain('Add formula:jq to Brewfile')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->expectsOutputToContain('Applied 1 change.')
        ->expectsOutputToContain("Backups are in {$this->dir}/backups.")
        ->expectsOutputToContain("What it changed is recorded in {$this->dir}/state/syncs/")
        ->assertExitCode(0);
});

it('logs each step with what it takes to put back', function () {
    onlyOtherCheck();
    $brewfile = realpath("{$this->dir}/repo/Brewfile");
    file_put_contents($brewfile, "brew \"mpv\"\n");
    answerWithQuestions();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsQuestion('How do you want to resolve it?', 'each')
        ->expectsQuestion('Other, untracked: formula:jq', 'add')
        ->expectsQuestion('Add Other items to which file?', 'Brewfile')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    expect(syncLog()['steps'])->toBe([[
        'item' => 'other:untracked:formula:jq',
        'action' => 'add',
        'description' => 'Add formula:jq to Brewfile',
        'effect' => [
            'kind' => 'edit',
            'path' => $brewfile,
            'backup' => "{$this->dir}/backups{$brewfile}",
            'sha256' => hash('sha256', "brew \"mpv\"\nbrew \"jq\"\n"),
        ],
    ]])
        ->and(file_get_contents("{$this->dir}/backups{$brewfile}"))->toBe("brew \"mpv\"\n");
});

it('logs a step that failed, with its error', function () {
    onlyOtherCheck()->uninstallError = 'jq is in use';
    answerWithQuestions();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsQuestion('How do you want to resolve it?', 'each')
        ->expectsQuestion('Other, untracked: formula:jq', 'uninstall')
        ->expectsConfirmation('Uninstall formula:jq?', 'yes')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->expectsOutputToContain('Applied 0 of 1 changes.')
        ->expectsOutputToContain('brew uninstall --formula jq: jq is in use')
        ->assertExitCode(1);

    expect(syncLog()['steps'])->toBe([[
        'item' => 'other:untracked:formula:jq',
        'action' => 'uninstall',
        'description' => 'brew uninstall --formula jq',
        'failed' => 'jq is in use',
    ]]);
});

it('offers to commit the manifests it changed when the repo is inside a git checkout', function () {
    onlyOtherCheck();
    answerWithQuestions();
    file_put_contents("{$this->dir}/brew/bin/git", "#!/bin/sh\n");
    chmod("{$this->dir}/brew/bin/git", 0755);
    $git = ["{$this->dir}/brew/bin/git", '-C', realpath("{$this->dir}/repo")];
    $shell = (new FakeShell)
        ->expect([...$git, 'rev-parse', '--is-inside-work-tree'], "true\n")
        ->expect([...$git, 'diff', '--stat', '--', 'Brewfile'], " Brewfile | 1 +\n")
        ->expect([...$git, 'add', '--', 'Brewfile'])
        ->expect([...$git, 'commit', '-m', 'dotsnitch: add jq', '--', 'Brewfile']);
    $this->app->instance(Shell::class, $shell);

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsQuestion('How do you want to resolve it?', 'review')
        ->expectsQuestion('Other, untracked: pick the ones to add to dotfiles', ['formula:jq'])
        ->expectsQuestion('Add Other items to which file?', 'Brewfile')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->expectsOutputToContain('Brewfile | 1 +')
        ->expectsConfirmation('Commit these changes to your dotfiles?', 'yes')
        ->assertExitCode(0);

    expect(array_map(fn (array $command) => $command[3], $shell->ran()))->toBe(['rev-parse', 'diff', 'add', 'commit'])
        ->and(file_get_contents("{$this->dir}/repo/Brewfile"))->toBe("brew \"jq\"\n")
        ->and(syncLog()['commit'])->toBe(['repo' => realpath("{$this->dir}/repo"), 'message' => 'dotsnitch: add jq']);
});

it("doesn't offer a commit when the repo isn't in a git checkout", function () {
    onlyOtherCheck();
    answerWithQuestions();
    file_put_contents("{$this->dir}/brew/bin/git", "#!/bin/sh\n");
    chmod("{$this->dir}/brew/bin/git", 0755);
    $git = ["{$this->dir}/brew/bin/git", '-C', realpath("{$this->dir}/repo")];
    $shell = (new FakeShell)->expect([...$git, 'rev-parse', '--is-inside-work-tree'], exitCode: 128);
    $this->app->instance(Shell::class, $shell);

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other'])
        ->expectsQuestion('How do you want to resolve it?', 'review')
        ->expectsQuestion('Other, untracked: pick the ones to add to dotfiles', ['formula:jq'])
        ->expectsQuestion('Add Other items to which file?', 'Brewfile')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    expect($shell->ran())->toHaveCount(1);
});

it('offers to commit a file it adopted into the repo', function () {
    $home = realpath($this->dir);
    $repo = "{$home}/repo";
    file_put_contents("{$repo}/install.conf.yaml", "- link:\n    ~/.zshrc: .zshrc\n");
    file_put_contents("{$repo}/.zshrc", "repo\n");
    file_put_contents("{$home}/.zshrc", "home\n");
    file_put_contents("{$this->dir}/brew/bin/git", "#!/bin/sh\n");
    chmod("{$this->dir}/brew/bin/git", 0755);
    $git = ["{$this->dir}/brew/bin/git", '-C', $repo];
    $shell = (new FakeShell)
        ->expect([...$git, 'rev-parse', '--is-inside-work-tree'], "true\n")
        ->expect([...$git, 'diff', '--stat', '--', '.zshrc'], " .zshrc | 2 +-\n")
        ->expect([...$git, 'add', '--', '.zshrc'])
        ->expect([...$git, 'commit', '-m', 'dotsnitch: adopt .zshrc', '--', '.zshrc']);
    $this->app->instance(Shell::class, $shell);
    $this->app->instance(Symlinks::class, new Symlinks(app(DotfilesRepo::class), $shell, app(ManifestEditor::class), ['HOME' => $home]));
    answerWithQuestions();

    $this->artisan('sync', ['--repo' => $repo, '--checks' => 'symlinks'])
        ->expectsQuestion('How do you want to resolve it?', 'each')
        ->expectsQuestion("Symlinked config files, conflict: {$home}/.zshrc", 'adopt')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->expectsConfirmation('Commit these changes to your dotfiles?', 'yes')
        ->assertExitCode(0);

    expect(array_map(fn (array $command) => $command[3], $shell->ran()))->toBe(['rev-parse', 'diff', 'add', 'commit'])
        ->and(file_get_contents("{$repo}/.zshrc"))->toBe("home\n")
        ->and(readlink("{$home}/.zshrc"))->toBe("{$repo}/.zshrc");
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
        ->and(app(ReportStore::class)->latest()->checks)->toBe(['other' => []])
        ->and(syncLog()['steps'][0]['effect'])->toBe(['kind' => 'ignore', 'check' => 'other', 'key' => 'formula:jq']);
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

it('installs what is missing with --install-missing, asks nothing and leaves the rest', function () {
    $other = onlyOtherCheck();
    $other->kinds = [DriftKind::Missing, DriftKind::Untracked];

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other', '--install-missing' => true, '--no-interaction' => true])
        ->expectsOutputToContain('These will run, in order:')
        ->expectsOutputToContain('brew install --formula jq')
        ->expectsOutputToContain('Applied 1 change.')
        ->assertExitCode(0);

    expect($other->ran)->toBe(['install jq'])
        ->and(file_get_contents("{$this->dir}/repo/Brewfile"))->toBe('')
        ->and(syncLog()['steps'][0]['effect']['command'])->toBe(['brew', 'install', '--formula', 'jq']);
});

it('only lists the installs for --install-missing on a dry run', function () {
    $other = onlyOtherCheck();
    $other->kinds = [DriftKind::Missing];

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other', '--install-missing' => true, '--dry-run' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Dry run. These would run, in order:')
        ->assertExitCode(0);

    expect($other->ran)->toBe([]);
});

it('says so when --install-missing finds nothing missing', function () {
    onlyOtherCheck();

    $this->artisan('sync', ['--repo' => "{$this->dir}/repo", '--checks' => 'other', '--install-missing' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Nothing to install.')
        ->assertExitCode(0);
});
