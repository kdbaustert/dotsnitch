<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\PackageSpec;
use App\Data\Package;

/**
 * A crate as name@version, the form cargo install takes, such as ripgrep@14.
 */
final class CargoSpec implements PackageSpec
{
    private const ENTRY = '/^([A-Za-z0-9][\w-]*)(?:@(.+))?$/';

    /**
     * @return ?Package null for anything that isn't a crate name
     */
    public function parse(string $entry): ?Package
    {
        return preg_match(self::ENTRY, $entry, $match) ? new Package($match[1], 'crate', $match[2] ?? null) : null;
    }

    /**
     * Such as ripgrep@14, or the bare name.
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
