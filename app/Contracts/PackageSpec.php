<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Package;

/**
 * How one manager writes a package on a list line or the command line, such as
 * "typescript@6" or "laravel/installer:^5".
 */
interface PackageSpec
{
    /**
     * @return ?Package null when the entry isn't a package this manager installs, such as
     *                  Composer's "php" or a shell variable
     */
    public function parse(string $entry): ?Package;

    /**
     * The package as an install argument, pinned when it has a version.
     */
    public function argument(Package $package): string;

    /**
     * What a new manifest line holds. Never a pin, so adding what's installed doesn't freeze it.
     */
    public function entry(Package $package): string;
}
