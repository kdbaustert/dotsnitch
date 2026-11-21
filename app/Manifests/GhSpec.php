<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\PackageSpec;
use App\Data\Package;

/**
 * A gh extension as owner/repo, with an optional @ref to pin a tag or commit, such as
 * dlvhdr/gh-dash@v4.0.0. Lowercased, since GitHub doesn't care about case. A name without an
 * owner is a local extension.
 */
final class GhSpec implements PackageSpec
{
    private const ENTRY = '~^([\w.-]+(?:/[\w.-]+)?)(?:@(.+))?$~';

    /**
     * @return ?Package null for anything that isn't owner/repo or a bare extension name
     */
    public function parse(string $entry): ?Package
    {
        if (! preg_match(self::ENTRY, $entry, $match)) {
            return null;
        }

        return new Package(strtolower($match[1]), 'extension', $match[2] ?? null);
    }

    /**
     * owner/repo only. gh takes a pin as --pin, which Gh adds.
     */
    public function argument(Package $package): string
    {
        return $package->name;
    }

    /**
     * Just owner/repo, such as dlvhdr/gh-dash, with no @ref.
     */
    public function entry(Package $package): string
    {
        return $package->name;
    }
}
