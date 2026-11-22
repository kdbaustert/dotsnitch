<?php

declare(strict_types=1);

use App\Data\Package;
use App\Manifests\BrewfileEdit;

const SAMPLE_BREWFILE = <<<'RUBY'
# Taps
tap "fif7y/tap"

# CLI
brew "jq"
brew 'svn', link: true # old alias
if OS.mac?
  brew "mpv"
end

# Apps
cask "zed"
mas "Xcode", id: 497799835

RUBY;

it('adds an entry after the last one of its type', function () {
    $edit = new BrewfileEdit(SAMPLE_BREWFILE);
    $edit->add(new Package('ripgrep', 'formula'));
    $edit->add(new Package('ghostty', 'cask'));

    expect($edit->contents())->toBe(<<<'RUBY'
        # Taps
        tap "fif7y/tap"

        # CLI
        brew "jq"
        brew 'svn', link: true # old alias
        brew "ripgrep"
        if OS.mac?
          brew "mpv"
        end

        # Apps
        cask "zed"
        cask "ghostty"
        mas "Xcode", id: 497799835

        RUBY);
});

it('puts a first tap above the first entry and a first cask after the formulae', function () {
    $edit = new BrewfileEdit("# Mine\nbrew \"jq\"\n");
    $edit->add(new Package('zed', 'cask'));
    $edit->add(new Package('fif7y/tap', 'tap'));

    expect($edit->contents())->toBe("# Mine\ntap \"fif7y/tap\"\nbrew \"jq\"\ncask \"zed\"\n");
});

it('adds to an empty file and skips a name that is already listed', function () {
    $empty = new BrewfileEdit('');
    $empty->add(new Package('jq', 'formula'));
    $listed = new BrewfileEdit(SAMPLE_BREWFILE);
    $listed->add(new Package('svn', 'formula'));

    expect($empty->contents())->toBe("brew \"jq\"\n")
        ->and($listed->contents())->toBe(SAMPLE_BREWFILE);
});

it('removes lines with options, comments, either quote, or an indent', function () {
    $edit = new BrewfileEdit(SAMPLE_BREWFILE);

    expect($edit->remove(new Package('svn', 'formula')))->toBeTrue()
        ->and($edit->remove(new Package('mpv', 'formula')))->toBeTrue()
        ->and($edit->remove(new Package('FIF7Y/Tap', 'tap')))->toBeTrue()
        ->and($edit->remove(new Package('zed', 'formula')))->toBeFalse()
        ->and($edit->contents())->toBe(<<<'RUBY'
            # Taps

            # CLI
            brew "jq"
            if OS.mac?
            end

            # Apps
            cask "zed"
            mas "Xcode", id: 497799835

            RUBY);
});

it("doesn't match a name that only starts the same", function () {
    $edit = new BrewfileEdit("brew \"jq-extra\"\n");

    expect($edit->remove(new Package('jq', 'formula')))->toBeFalse();
});

it('swaps an entry in place, type included, and keeps the rest of the line', function () {
    $edit = new BrewfileEdit(SAMPLE_BREWFILE);

    expect($edit->replace(new Package('svn', 'formula'), new Package('subversion', 'formula')))->toBeTrue()
        ->and($edit->replace(new Package('mpv', 'formula'), new Package('iina', 'cask')))->toBeTrue()
        ->and($edit->contents())->toContain("brew 'subversion', link: true # old alias\n")
        ->and($edit->contents())->toContain("  cask \"iina\"\n");
});

it('drops the old entry when its replacement is already listed', function () {
    $edit = new BrewfileEdit("brew \"youtube-dl\"\nbrew \"yt-dlp\"\n");

    expect($edit->replace(new Package('youtube-dl', 'formula'), new Package('yt-dlp', 'formula')))->toBeTrue()
        ->and($edit->contents())->toBe("brew \"yt-dlp\"\n");
});

it('keeps a file without a final newline that way', function () {
    $edit = new BrewfileEdit("brew \"jq\"\nbrew \"mpv\"");
    $edit->remove(new Package('jq', 'formula'));

    expect($edit->contents())->toBe('brew "mpv"');
});

it('gives back the same text after adding an entry and removing it again', function (Package $package) {
    $edit = new BrewfileEdit(SAMPLE_BREWFILE);
    $edit->add($package);
    $edit = new BrewfileEdit($edit->contents());

    expect($edit->remove($package))->toBeTrue()
        ->and($edit->contents())->toBe(SAMPLE_BREWFILE);
})->with([
    'tap' => [new Package('acme/tools', 'tap')],
    'formula' => [new Package('wget', 'formula')],
    'cask' => [new Package('iterm2', 'cask')],
    'app' => [new Package('409222199', 'app')],
    'extension' => [new Package('editorconfig.editorconfig', 'extension')],
]);

it('writes a mas line with the name it was given, and finds one by its id', function () {
    $edit = new BrewfileEdit(SAMPLE_BREWFILE, ['409222199' => 'Cyberduck', '1' => 'Say "hi" #1']);
    $edit->add(new Package('409222199', 'app'));
    $edit->add(new Package('1', 'app'));
    $edit->add(new Package('497799835', 'app'));

    expect($edit->contents())->toEndWith("mas \"Xcode\", id: 497799835\nmas \"Cyberduck\", id: 409222199\nmas \"Say \\\"hi\\\" \\#1\", id: 1\n")
        ->and($edit->lists(new Package('4977998', 'app')))->toBeFalse()
        ->and($edit->remove(new Package('497799835', 'app')))->toBeTrue();
});

it('puts a first vscode line after everything else', function () {
    $edit = new BrewfileEdit("brew \"jq\"\nmas \"Xcode\", id: 497799835\n");
    $edit->add(new Package('editorconfig.editorconfig', 'extension'));

    expect($edit->contents())->toBe("brew \"jq\"\nmas \"Xcode\", id: 497799835\nvscode \"editorconfig.editorconfig\"\n")
        ->and($edit->lists(new Package('EditorConfig.EditorConfig', 'extension')))->toBeTrue();
});
