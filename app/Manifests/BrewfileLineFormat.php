<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\ManifestEdit;
use App\Contracts\ManifestFormat;
use App\Data\Package;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The mas or vscode lines of a Brewfile, read from its text. brew bundle list prints an app's
 * name without its id, so brew can't read these the way it does for BrewfileFormat. A line inside
 * a Ruby conditional counts as listed whichever way the condition goes.
 */
final class BrewfileLineFormat implements ManifestFormat
{
    /** Such as `mas "Xcode", id: 497799835`, capturing the name and the id. */
    private const MAS_LINE = '/^\s*mas\s+(["\'])(.*?)\1\s*,\s*id:\s*(\d+)/m';

    /** Such as `vscode "editorconfig.editorconfig"`. */
    private const VSCODE_LINE = '/^\s*vscode\s+(["\'])(.+?)\1/m';

    /**
     * @param  string  $type  "app" for the mas lines, "extension" for the vscode ones
     * @param  array<string, string>  $titles  app name by id, written into new mas lines
     */
    public function __construct(
        private readonly string $type,
        private readonly array $titles = [],
    ) {}

    /**
     * True for Brewfile, .Brewfile and suffixed copies such as Brewfile.work.
     */
    public static function isBrewfile(string $path): bool
    {
        return preg_match('/^\.?Brewfile(\..+)?$/', basename($path)) === 1;
    }

    /**
     * App names by id from a Brewfile's mas lines.
     *
     * @return array<string, string>
     */
    public static function appTitles(string $contents): array
    {
        preg_match_all(self::MAS_LINE, $contents, $matches, PREG_SET_ORDER);

        return array_column($matches, 2, 3);
    }

    /**
     * Extension ids come back in lowercase, the way VS Code compares them.
     *
     * @throws RuntimeException when the file can't be read
     */
    public function read(string $path): Collection
    {
        if (! is_file($path)) {
            throw new RuntimeException("Can't read {$path}");
        }

        $contents = (string) file_get_contents($path);

        if ($this->type === 'app') {
            return collect(array_keys(self::appTitles($contents)))->map(fn (int|string $id) => new Package((string) $id, 'app'));
        }

        preg_match_all(self::VSCODE_LINE, $contents, $matches);

        return collect($matches[2])->map(fn (string $name) => new Package(strtolower($name), 'extension'));
    }

    public function edit(string $contents): ManifestEdit
    {
        return new BrewfileEdit($contents, $this->titles);
    }
}
