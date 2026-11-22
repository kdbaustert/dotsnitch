<?php

declare(strict_types=1);

use App\Contracts\Shell;
use App\Support\JsonFile;
use Laravel\Prompts\Prompt;
use Tests\Fakes\FakeShell;

beforeEach(function () {
    fakeMac($this);
    $this->shell = new FakeShell;
    app()->instance(Shell::class, $this->shell);
    // Prompts skip themselves when stdin isn't a terminal, so Laravel asks console questions instead.
    app()['env'] = 'testing';
});

afterEach(function () {
    Prompt::interactive(false);
    (new ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * Saves a sync log holding these effects, one step each, and returns its path.
 *
 * @param  list<array<string, mixed>>  $effects
 */
function syncRecord(array $effects, string $name = '2026-11-22T090000'): string
{
    $path = test()->dir."/state/syncs/{$name}.json";
    JsonFile::write($path, [
        'started_at' => '2026-11-22T09:00:00+00:00',
        'steps' => array_map(fn (array $effect) => ['item' => 'x', 'action' => 'add', 'description' => 'x', 'effect' => $effect], $effects),
        'commit' => null,
    ]);

    return $path;
}

it('says so when no sync has been recorded', function () {
    $this->artisan('undo')->expectsOutputToContain('No sync has changed anything yet.')->assertExitCode(0);
});

it('restores an edited file from its backup and removes one the sync created', function () {
    $brewfile = "{$this->dir}/repo/Brewfile";
    $overlay = "{$this->dir}/repo/Brewfile.mac";
    $backup = "{$this->dir}/backups/Brewfile";
    mkdir(dirname($backup), 0755, true);
    file_put_contents($backup, "brew \"mpv\"\n");
    file_put_contents($brewfile, "brew \"mpv\"\nbrew \"jq\"\nbrew \"fd\"\n");
    file_put_contents($overlay, "brew \"rg\"\n");
    $record = syncRecord([
        ['kind' => 'edit', 'path' => $brewfile, 'backup' => $backup, 'sha256' => hash('sha256', "brew \"mpv\"\nbrew \"jq\"\n")],
        ['kind' => 'edit', 'path' => $brewfile, 'backup' => $backup, 'sha256' => hash_file('sha256', $brewfile)],
        ['kind' => 'edit', 'path' => $overlay, 'backup' => null, 'sha256' => hash_file('sha256', $overlay)],
    ]);

    $this->artisan('undo')
        ->expectsOutputToContain("cp {$backup} {$brewfile}")
        ->expectsConfirmation('Undo these changes?', 'yes')
        ->expectsOutputToContain('Put back 2 of 2.')
        ->assertExitCode(0);

    expect(file_get_contents($brewfile))->toBe("brew \"mpv\"\n")
        ->and(file_exists($overlay))->toBeFalse()
        ->and(JsonFile::read($record))->toHaveKey('undone_at');
});

it('leaves a file that changed since the sync, and fails', function () {
    $brewfile = "{$this->dir}/repo/Brewfile";
    file_put_contents($brewfile, "brew \"edited-by-hand\"\n");
    $record = syncRecord([['kind' => 'edit', 'path' => $brewfile, 'backup' => "{$this->dir}/backups/Brewfile", 'sha256' => hash('sha256', 'what sync wrote')]]);

    $this->artisan('undo')
        ->expectsConfirmation('Undo these changes?', 'yes')
        ->expectsOutputToContain('Put back 0 of 1.')
        ->expectsOutputToContain("{$brewfile} changed since the sync")
        ->assertExitCode(1);

    expect(file_get_contents($brewfile))->toBe("brew \"edited-by-hand\"\n")
        ->and(JsonFile::read($record))->not->toHaveKey('undone_at');
});

it('takes a back-up-and-link apart in reverse: the link goes, then the file comes back', function () {
    $target = "{$this->dir}/.zshrc";
    $source = "{$this->dir}/repo/.zshrc";
    $backup = "{$this->dir}/backups/.zshrc";
    mkdir(dirname($backup), 0755, true);
    file_put_contents($backup, 'mine');
    touch($source);
    symlink($source, $target);
    syncRecord([
        ['kind' => 'move', 'from' => $target, 'to' => $backup],
        ['kind' => 'link', 'target' => $target, 'source' => $source],
    ]);

    $this->artisan('undo')->expectsConfirmation('Undo these changes?', 'yes')->assertExitCode(0);

    expect(is_link($target))->toBeFalse()
        ->and(file_get_contents($target))->toBe('mine')
        ->and(file_exists($backup))->toBeFalse();
});

it('points a repointed link back, and puts a removed link back', function () {
    $repointed = "{$this->dir}/.vimrc";
    $removed = "{$this->dir}/.gone";
    symlink("{$this->dir}/repo/.vimrc", $repointed);
    syncRecord([
        ['kind' => 'repoint', 'target' => $repointed, 'source' => "{$this->dir}/repo/.vimrc", 'pointed_to' => '/old/dotfiles/.vimrc'],
        ['kind' => 'unlink', 'target' => $removed, 'pointed_to' => '/old/dotfiles/.gone'],
    ]);

    $this->artisan('undo')->expectsConfirmation('Undo these changes?', 'yes')->assertExitCode(0);

    expect(readlink($repointed))->toBe('/old/dotfiles/.vimrc')
        ->and(readlink($removed))->toBe('/old/dotfiles/.gone');
});

it('leaves a link that points somewhere else now', function () {
    $target = "{$this->dir}/.zshrc";
    symlink('/somewhere/else', $target);
    syncRecord([['kind' => 'link', 'target' => $target, 'source' => "{$this->dir}/repo/.zshrc"]]);

    $this->artisan('undo')
        ->expectsConfirmation('Undo these changes?', 'yes')
        ->expectsOutputToContain("{$target} changed since the sync")
        ->assertExitCode(1);

    expect(readlink($target))->toBe('/somewhere/else');
});

it('stops ignoring what the sync ignored, and names the commands it leaves alone', function () {
    $this->settings->set('ignore.brew', ['formula:jq', 'formula:fd']);
    syncRecord([
        ['kind' => 'ignore', 'check' => 'brew', 'key' => 'formula:jq'],
        ['kind' => 'command', 'command' => ['brew', 'install', '--formula', 'mpv']],
    ]);

    $this->artisan('undo')
        ->expectsOutputToContain('These ran and stay as they are:')
        ->expectsOutputToContain('brew install --formula mpv')
        ->doesntExpectOutputToContain('Add --packages')
        ->expectsConfirmation('Undo these changes?', 'yes')
        ->assertExitCode(0);

    expect($this->settings->get('ignore.brew'))->toBe(['formula:fd']);
});

it('leaves packages alone without --packages, and says how to take them back', function () {
    syncRecord([
        ['kind' => 'command', 'command' => ['brew', 'install', '--formula', 'mpv'], 'undo' => [['brew', 'uninstall', '--formula', 'mpv']], 'environment' => []],
    ]);

    $this->artisan('undo')
        ->expectsOutputToContain('Add --packages to take back the ones that can be.')
        ->expectsOutputToContain('changed nothing that can be put back')
        ->assertExitCode(0);

    expect($this->shell->ran())->toBe([]);
});

it('takes installs and uninstalls back with --packages, last command first', function () {
    $environment = ['HOMEBREW_NO_AUTO_UPDATE' => '1'];
    syncRecord([
        ['kind' => 'command', 'command' => ['brew', 'install', '--formula', 'mpv'], 'undo' => [['brew', 'uninstall', '--formula', 'mpv']], 'environment' => $environment],
        ['kind' => 'command', 'command' => ['npm', 'rm', '-g', 'serve'], 'undo' => [['npm', 'i', '-g', 'serve']], 'environment' => []],
        ['kind' => 'command', 'command' => ['pipx', 'install', '--force', 'black==24.1.0']],
    ]);
    $this->shell->expect(['npm', 'i', '-g', 'serve'])->expect(['brew', 'uninstall', '--formula', 'mpv']);

    $this->artisan('undo --packages')
        ->expectsOutputToContain('These ran and stay as they are:')
        ->expectsOutputToContain('pipx install --force black==24.1.0')
        ->expectsOutputToContain('brew uninstall --formula mpv')
        ->expectsConfirmation('Undo these changes?', 'yes')
        ->expectsOutputToContain('Put back 2 of 2.')
        ->assertExitCode(0);

    expect($this->shell->ran())->toBe([['npm', 'i', '-g', 'serve'], ['brew', 'uninstall', '--formula', 'mpv']])
        ->and($this->shell->environmentOf(['brew', 'uninstall', '--formula', 'mpv']))->toBe($environment);
});

it('reports a package command that fails, and carries on with the rest', function () {
    syncRecord([
        ['kind' => 'command', 'command' => ['brew', 'install', '--formula', 'mpv'], 'undo' => [['brew', 'uninstall', '--formula', 'mpv']], 'environment' => []],
        ['kind' => 'command', 'command' => ['npm', 'rm', '-g', 'serve'], 'undo' => [['npm', 'i', '-g', 'serve']], 'environment' => []],
    ]);
    $this->shell
        ->expect(['npm', 'i', '-g', 'serve'], exitCode: 1, errorOutput: "npm error 404\n")
        ->expect(['brew', 'uninstall', '--formula', 'mpv']);

    $this->artisan('undo --packages')
        ->expectsConfirmation('Undo these changes?', 'yes')
        ->expectsOutputToContain('Put back 1 of 2.')
        ->expectsOutputToContain('Left alone: npm i -g serve: npm error 404')
        ->assertExitCode(1);
});

it('undoes the newest sync, only once, and changes nothing on a dry run', function () {
    $old = "{$this->dir}/.old";
    $new = "{$this->dir}/.new";
    symlink('/a', $old);
    symlink('/b', $new);
    syncRecord([['kind' => 'link', 'target' => $old, 'source' => '/a']], '2026-11-21T090000');
    syncRecord([['kind' => 'link', 'target' => $new, 'source' => '/b']], '2026-11-22T090000');

    $this->artisan('undo', ['--dry-run' => true])->expectsOutputToContain("rm {$new}")->assertExitCode(0);
    expect(is_link($new))->toBeTrue();

    $this->artisan('undo')->expectsConfirmation('Undo these changes?', 'yes')->assertExitCode(0);
    $this->artisan('undo')->expectsOutputToContain('was already undone')->assertExitCode(0);

    expect(is_link($new))->toBeFalse()->and(is_link($old))->toBeTrue();
});

it('takes the record to undo as an argument, and fails on one that is not there', function () {
    $old = "{$this->dir}/.old";
    symlink('/a', $old);
    $record = syncRecord([['kind' => 'link', 'target' => $old, 'source' => '/a']], '2026-11-21T090000');
    syncRecord([], '2026-11-22T090000');

    $this->artisan('undo', ['record' => $record])->expectsConfirmation('Undo these changes?', 'yes')->assertExitCode(0);
    $this->artisan('undo', ['record' => "{$this->dir}/nope.json"])->expectsOutputToContain('No sync record at')->assertExitCode(1);

    expect(is_link($old))->toBeFalse();
});
