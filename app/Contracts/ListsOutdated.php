<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Package;
use RuntimeException;

/**
 * A check whose package manager can say which installed packages have a newer version.
 */
interface ListsOutdated
{
    /**
     * @return list<array{package: Package, latest: string}> each package with the version installed now
     *
     * @throws RuntimeException when the package manager can't say
     */
    public function outdated(): array;
}
