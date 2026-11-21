<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\PackageSpec;
use App\Data\Package;

/**
 * name@version for npm and pnpm. A scoped name starts with @ too, so only an @ after the name
 * starts the version.
 */
final class NodeSpec implements PackageSpec
{
    private const ENTRY = '~^((?:@[a-z0-9][\w.-]*/)?[a-z0-9][\w.-]*)(?:@(.+))?$~i';

    /**
     * @return ?Package null for anything that isn't a package name, like a ${pkgs[@]} expansion
     */
    public function parse(string $entry): ?Package
    {
        if (! preg_match(self::ENTRY, $entry, $match)) {
            return null;
        }

        return new Package($match[1], 'package', $match[2] ?? null);
    }

    /**
     * Such as typescript@6, or the bare name when there's no pin.
     */
    public function argument(Package $package): string
    {
        return $package->version === null ? $package->name : "{$package->name}@{$package->version}";
    }

    /**
     * The bare name.
     */
    public function entry(Package $package): string
    {
        return $package->name;
    }
}
