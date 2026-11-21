<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\PackageSpec;
use App\Data\Package;

/**
 * vendor/name with an optional :constraint, the way `composer require` takes it.
 */
final class ComposerSpec implements PackageSpec
{
    /** Platform packages such as php and ext-json have no vendor, so they don't match. */
    private const ENTRY = '~^([a-z0-9][\w.-]*/[a-z0-9][\w.-]*)(?::(.+))?$~i';

    /**
     * Lowercases the name, since Composer treats names that way and reports them in lowercase.
     */
    public function parse(string $entry): ?Package
    {
        if (! preg_match(self::ENTRY, $entry, $match)) {
            return null;
        }

        return new Package(strtolower($match[1]), 'package', $match[2] ?? null);
    }

    /**
     * Such as laravel/installer:^5, or the bare name when there's no pin.
     */
    public function argument(Package $package): string
    {
        return $package->version === null ? $package->name : "{$package->name}:{$package->version}";
    }

    /**
     * Just vendor/name, such as laravel/installer.
     */
    public function entry(Package $package): string
    {
        return $package->name;
    }
}
