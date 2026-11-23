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
     * SyncTarget's label, which `outdated` prints beside each package.
     */
    public function label(): string;

    /**
     * SyncTarget's answer, so `outdated` skips a package manager that isn't installed.
     */
    public function isAvailable(): bool;

    /**
     * @return list<array{package: Package, latest: string}> each package with the version installed now
     *
     * @throws RuntimeException when the package manager can't say
     */
    public function outdated(): array;
}
