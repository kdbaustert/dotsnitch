<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\ManifestEdit;
use App\Contracts\ManifestFormat;
use App\Contracts\Shell;
use App\Data\Package;
use App\Targets\Homebrew;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A Brewfile is Ruby and can hold conditionals, so Homebrew parses it, not us. Edits are plain
 * line edits (see BrewfileEdit). mas lines are left to the Mac App Store check.
 */
final class BrewfileFormat implements ManifestFormat
{
    /** Package type to the `brew bundle list` flag that lists it. */
    private const TYPES = ['tap' => '--tap', 'formula' => '--formula', 'cask' => '--cask'];

    /**
     * @param  string  $brew  absolute path to the brew binary
     */
    public function __construct(
        private readonly Shell $shell,
        private readonly string $brew,
    ) {}

    /**
     * @throws RuntimeException when brew can't parse the file
     */
    public function read(string $path): Collection
    {
        $packages = collect();

        foreach (self::TYPES as $type => $flag) {
            $result = $this->shell->run(
                [$this->brew, 'bundle', 'list', "--file={$path}", $flag],
                environment: Homebrew::ENVIRONMENT,
            );

            if (! $result->successful()) {
                throw new RuntimeException("brew couldn't read {$path}: ".trim($result->errorOutput));
            }

            foreach (array_filter(array_map(trim(...), explode("\n", $result->output))) as $name) {
                $packages->push(new Package($name, $type));
            }
        }

        return $packages;
    }

    /**
     * Opens the Brewfile text for line edits.
     */
    public function edit(string $contents): ManifestEdit
    {
        return new BrewfileEdit($contents);
    }
}
