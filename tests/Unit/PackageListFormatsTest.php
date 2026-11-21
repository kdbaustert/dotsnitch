<?php

declare(strict_types=1);

use App\Contracts\ManifestFormat;
use App\Data\Package;
use App\Manifests\BashArrayEdit;
use App\Manifests\BashArrayFormat;
use App\Manifests\CargoSpec;
use App\Manifests\ComposerSpec;
use App\Manifests\GhSpec;
use App\Manifests\JsonRequireEdit;
use App\Manifests\JsonRequireFormat;
use App\Manifests\LineListEdit;
use App\Manifests\LineListFormat;
use App\Manifests\MasSpec;
use App\Manifests\NodeSpec;
use App\Manifests\PythonSpec;

/**
 * @return string a temp file holding $contents, for tests that read from disk
 */
function scratchManifest(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'dotsnitch-');
    file_put_contents($path, $contents);

    return $path;
}

/**
 * @param  iterable<Package>  $packages
 * @return list<string> name, then @version when there is one
 */
function namesOf(iterable $packages): array
{
    return collect($packages)->map(fn (Package $package) => $package->name.($package->version === null ? '' : "@{$package->version}"))->values()->all();
}

it('splits a pin from the name, scoped names included', function () {
    $spec = new NodeSpec;

    expect($spec->parse('typescript@6'))->toEqual(new Package('typescript', 'package', '6'))
        ->and($spec->parse('@prettier/plugin-php'))->toEqual(new Package('@prettier/plugin-php', 'package'))
        ->and($spec->parse('@vue/cli@^5.0'))->toEqual(new Package('@vue/cli', 'package', '^5.0'))
        ->and($spec->parse('${pkgs[@]}'))->toBeNull()
        ->and($spec->argument(new Package('typescript', 'package', '6')))->toBe('typescript@6');
});

it('skips Composer platform packages and lowercases names', function () {
    $spec = new ComposerSpec;

    expect($spec->parse('php'))->toBeNull()
        ->and($spec->parse('ext-json'))->toBeNull()
        ->and($spec->parse('Laravel/Installer:^5'))->toEqual(new Package('laravel/installer', 'package', '^5'))
        ->and($spec->argument(new Package('laravel/installer', 'package', '^5')))->toBe('laravel/installer:^5');
});

it('reads a mas id with or without its name, and finds each name in a script', function () {
    $spec = new MasSpec(['1436522307' => 'Transmit 5']);

    expect($spec->parse('497799835:Xcode'))->toEqual(new Package('497799835', 'app'))
        ->and($spec->parse('497799835'))->toEqual(new Package('497799835', 'app'))
        ->and($spec->parse('Xcode'))->toBeNull()
        ->and($spec->entry(new Package('1436522307', 'app')))->toBe('1436522307:Transmit 5')
        ->and($spec->entry(new Package('497799835', 'app')))->toBe('497799835')
        ->and(MasSpec::titlesIn("apps=(\n  \"497799835:Xcode\"  # IDE\n  '1436522307:Transmit 5'\n)\nmas install \"\${app%%:*}\"\n"))
        ->toBe(['497799835' => 'Xcode', '1436522307' => 'Transmit 5']);
});

it('reads gh extensions as lowercase owner/repo with an optional pin', function () {
    $spec = new GhSpec;

    expect($spec->parse('Dlvhdr/gh-dash@v4.7.1'))->toEqual(new Package('dlvhdr/gh-dash', 'extension', 'v4.7.1'))
        ->and($spec->parse('gh-mine'))->toEqual(new Package('gh-mine', 'extension'))
        ->and($spec->parse('https://github.com/dlvhdr/gh-dash'))->toBeNull();
});

it('normalizes Python names and keeps extras and the specifier, but skips URLs', function () {
    $spec = new PythonSpec;
    $package = $spec->parse('HTTPie[socks] >=3.2');

    expect(PythonSpec::normalized('Poetry_Plugin..Export'))->toBe('poetry-plugin-export')
        ->and($package)->toEqual(new Package('httpie', 'package', '[socks] >=3.2'))
        ->and($spec->argument($package))->toBe('httpie[socks] >=3.2')
        ->and($spec->entry($package))->toBe('httpie')
        ->and($spec->parse('git+https://github.com/psf/black'))->toBeNull()
        ->and($spec->parse('./tools/local'))->toBeNull();
});

it('reads cargo crates with an optional @version', function () {
    $spec = new CargoSpec;
    $package = $spec->parse('ripgrep@14.1.1');

    expect($package)->toEqual(new Package('ripgrep', 'crate', '14.1.1'))
        ->and($spec->argument($package))->toBe('ripgrep@14.1.1')
        ->and($spec->argument(new Package('bat', 'crate')))->toBe('bat')
        ->and($spec->parse('--locked'))->toBeNull();
});

it('quotes a plain list entry that has a space, and reads it back whole', function () {
    $edit = new LineListEdit("497799835:Xcode\n", new MasSpec(['1436522307' => 'Transmit 5']));

    $edit->add(new Package('1436522307', 'app'));

    expect($edit->contents())->toBe("497799835:Xcode\n\"1436522307:Transmit 5\"\n")
        ->and(namesOf((new LineListEdit($edit->contents(), new MasSpec))->entries()))->toBe(['497799835', '1436522307']);
});

it('reads a plain list, skipping comments and blank lines', function () {
    $edit = new LineListEdit("# editors\nneovim\n\ntypescript@6  # pinned\n", new NodeSpec);

    expect(namesOf($edit->entries()))->toBe(['neovim', 'typescript@6']);
});

it('adds to a plain list after the last entry and removes a pinned one by name', function () {
    $edit = new LineListEdit("neovim\ntypescript@6 # pinned\n\n", new NodeSpec);

    $edit->add(new Package('prettier', 'package', '3.3.0'));
    $removed = $edit->remove(new Package('typescript', 'package'));

    expect($removed)->toBeTrue()
        ->and($edit->contents())->toBe("neovim\nprettier\n\n");
});

it('leaves a line holding two names for the user to edit', function () {
    $edit = new LineListEdit("neovim prettier\n", new NodeSpec);

    expect($edit->remove(new Package('prettier', 'package')))->toBeFalse()
        ->and(namesOf($edit->entries()))->toBe(['neovim', 'prettier']);
});

it('keeps the comment when it swaps a list entry', function () {
    $edit = new LineListEdit("  old-cli@2 # for work\n", new NodeSpec);

    $edit->replace(new Package('old-cli', 'package'), new Package('new-cli', 'package'));

    expect($edit->contents())->toBe("  new-cli # for work\n");
});

/** Shaped like a dotfiles setup/pnpm.sh. */
const PNPM_SCRIPT = <<<'SH'
#!/usr/bin/env bash
pkgs=(
  # Linters
  eslint
  "@prettier/plugin-php"
  typescript@6 # stay on 6 until the LSP catches up
  "${extra[@]}"
)
pnpm add -g "${pkgs[@]}"

SH;

it('reads every bash array item, skipping comments and variables', function () {
    $edit = new BashArrayEdit(PNPM_SCRIPT."tools=(ntl svgo) # one-liner\npnpm add -g \"\${tools[@]}\"\n", new NodeSpec);

    expect(namesOf($edit->entries()))->toBe(['eslint', '@prettier/plugin-php', 'typescript@6', 'ntl', 'svgo']);
});

it('reads an array that closes on its last item, and adds above that line', function () {
    $edit = new BashArrayEdit("pkgs=(\n  eslint\n  svgo) # done\npnpm add -g \"\${pkgs[@]}\"\n", new NodeSpec);

    $edit->add(new Package('prettier', 'package'));

    expect(namesOf($edit->entries()))->toBe(['eslint', 'prettier', 'svgo'])
        ->and($edit->contents())->toBe("pkgs=(\n  eslint\n  prettier\n  svgo) # done\npnpm add -g \"\${pkgs[@]}\"\n");
});

it('reads items appended with +=(', function () {
    $edit = new BashArrayEdit("pkgs=(\n  eslint\n)\npkgs+=(svgo)\npnpm add -g \"\${pkgs[@]}\"\n", new NodeSpec);

    expect(namesOf($edit->entries()))->toBe(['eslint', 'svgo']);
});

it('skips an array the script never expands', function () {
    $edit = new BashArrayEdit("dirs=(src lib)\nmkdir -p \"\$HOME/\${dirs[0]}\"\n".PNPM_SCRIPT, new NodeSpec);

    expect(namesOf($edit->entries()))->toBe(['eslint', '@prettier/plugin-php', 'typescript@6']);
});

it('adds inside the first multi-line array with the same indent', function () {
    $edit = new BashArrayEdit(PNPM_SCRIPT, new NodeSpec);

    $edit->add(new Package('prettier', 'package'));

    expect($edit->contents())->toContain("  \"\${extra[@]}\"\n  prettier\n)\n");
});

it('removes a quoted bash item and keeps the rest of the script', function () {
    $edit = new BashArrayEdit(PNPM_SCRIPT, new NodeSpec);

    $edit->remove(new Package('@prettier/plugin-php', 'package'));

    expect($edit->contents())->toBe(str_replace("  \"@prettier/plugin-php\"\n", '', PNPM_SCRIPT));
});

it('reads a one-line array but adds nothing to it', function () {
    $edit = new BashArrayEdit("pkgs=(eslint)\n", new NodeSpec);

    expect($edit->remove(new Package('eslint', 'package')))->toBeFalse()
        ->and(fn () => $edit->add(new Package('prettier', 'package')))
        ->toThrow(RuntimeException::class, 'There is no multi-line array to add it to. Add it by hand.');
});

it('refuses a script with no array', function () {
    $path = scratchManifest("pnpm add -g eslint\n");

    try {
        expect(fn () => (new BashArrayFormat(new NodeSpec))->read($path))
            ->toThrow(RuntimeException::class, basename($path).' has no bash array, like name=( ... ), to read packages from');
    } finally {
        unlink($path);
    }
});

it('reads a JSON section with each constraint as the version', function () {
    $path = scratchManifest('{"require": {"php": "^8.2", "ext-json": "*", "Psr/Log": "^3.0"}}');

    try {
        $packages = (new JsonRequireFormat('require', new ComposerSpec))->read($path);
    } finally {
        unlink($path);
    }

    expect(namesOf($packages))->toBe(['psr/log@^3.0']);
});

it('adds a caret constraint and keeps the indent, the newline and an empty object', function () {
    $edit = new JsonRequireEdit("{\n  \"name\": \"globals\",\n  \"config\": {},\n  \"dependencies\": {\n    \"neovim\": \"^5.5.0\"\n  }\n}\n", 'dependencies');

    $edit->add(new Package('typescript', 'package', '6.0.3'));

    expect($edit->contents())->toBe("{\n  \"name\": \"globals\",\n  \"config\": {},\n  \"dependencies\": {\n    \"neovim\": \"^5.5.0\",\n    \"typescript\": \"^6.0.3\"\n  }\n}\n");
});

it('creates the section, and writes * for a version that is not a number', function () {
    $edit = new JsonRequireEdit("{\n    \"require\": []\n}\n", 'require');

    $edit->add(new Package('acme/tool', 'package', 'dev-main'));
    $edit->add(new Package('fzaninotto/faker', 'package', 'v1.5.0'));

    expect($edit->contents())->toBe("{\n    \"require\": {\n        \"acme/tool\": \"*\",\n        \"fzaninotto/faker\": \"^1.5.0\"\n    }\n}\n");
});

it('removes a JSON entry whatever its case, and swaps one in place', function () {
    $edit = new JsonRequireEdit('{"require": {"Psr/Log": "^3.0", "acme/old": "^1", "acme/last": "^2"}}', 'require');

    $removed = $edit->remove(new Package('psr/log', 'package'));
    $edit->replace(new Package('acme/old', 'package'), new Package('acme/new', 'package', '2.1.0'));

    expect($removed)->toBeTrue()
        ->and(json_decode($edit->contents(), true))->toBe(['require' => ['acme/new' => '^2.1.0', 'acme/last' => '^2']]);
});

it('reads the same packages and text after adding one and removing it again', function (ManifestFormat $format, string $contents, Package $package, string $listedAs) {
    $path = scratchManifest($contents);

    try {
        $before = namesOf($format->read($path));
        $edit = $format->edit($contents);
        $edit->add($package);
        file_put_contents($path, $edit->contents());
        $added = namesOf($format->read($path));
        $edit = $format->edit($edit->contents());
        $removed = $edit->remove($package);
        file_put_contents($path, $edit->contents());

        expect($added)->toBe([...$before, $listedAs])
            ->and($removed)->toBeTrue()
            ->and(namesOf($format->read($path)))->toBe($before)
            ->and($edit->contents())->toBe($contents);
    } finally {
        unlink($path);
    }
})->with([
    'plain list' => [new LineListFormat(new NodeSpec), "# Language servers\nneovim\n\n# Tools\neslint@9 # pinned\n", new Package('prettier', 'package'), 'prettier'],
    'bash array' => [new BashArrayFormat(new NodeSpec), "#!/usr/bin/env bash\n# Globals\npackages=(\n  neovim # editor\n  eslint\n)\npnpm add -g \"\${packages[@]}\"\n", new Package('prettier', 'package'), 'prettier'],
    'JSON section' => [new JsonRequireFormat('require', new ComposerSpec), "{\n    \"require\": {\n        \"laravel/installer\": \"^5.0\"\n    }\n}\n", new Package('psr/log', 'package', '3.0.2'), 'psr/log@^3.0.2'],
]);
