<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\PackageSpec;
use App\Data\Package;

/**
 * An App Store id, with an optional :Name after it the way mas.sh lists them ("497799835:Xcode").
 * mas only takes the id; the name keeps the list readable. A name with a space needs quotes,
 * or the words after the space read as entries of their own.
 */
final class MasSpec implements PackageSpec
{
    private const ENTRY = '/^(\d+)(?::.*)?$/';

    /**
     * An id and the name after its colon. A quoted name runs to the closing quote, so it can hold
     * the other kind. A bare one runs to a quote, comment or line end.
     */
    private const TITLED = '/(?|"(\d+):([^"#\n]+)"|\'(\d+):([^\'#\n]+)\'|(?<![\w"\'])(\d+):([^"\'#\n]+))/';

    /**
     * @param  array<string, string>  $titles  app name by id, written into new entries
     */
    public function __construct(
        private readonly array $titles = [],
    ) {}

    /**
     * App names by id from a manifest's id:Name entries.
     *
     * @return array<string, string>
     */
    public static function titlesIn(string $contents): array
    {
        preg_match_all(self::TITLED, $contents, $matches, PREG_SET_ORDER);
        $titles = [];

        foreach ($matches as [, $id, $title]) {
            $titles[$id] = trim($title);
        }

        return $titles;
    }

    /**
     * @return ?Package null for anything that doesn't start with an id
     */
    public function parse(string $entry): ?Package
    {
        return preg_match(self::ENTRY, $entry, $match) ? new Package($match[1], 'app') : null;
    }

    /**
     * The id. The App Store always installs the current version.
     */
    public function argument(Package $package): string
    {
        return $package->name;
    }

    /**
     * id:Name when the name is known, so the line reads like the rest of mas.sh. Only the id when
     * the name holds a character double quotes don't keep as it is in a script, or a #.
     */
    public function entry(Package $package): string
    {
        $title = $this->titles[$package->name] ?? null;

        return $title === null || strpbrk($title, '"$`\\#') !== false ? $package->name : "{$package->name}:{$title}";
    }
}
