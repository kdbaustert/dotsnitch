<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Package;
use ErrorException;
use RuntimeException;

/**
 * A check whose package manager can say which installed packages have a newer version.
 */
interface ListsOutdated
{
    /**
     * SyncTarget's key, which `outdated --checks` and `--json` name it by.
     */
    public function key(): string;

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
     * @throws ErrorException for a PHP warning, which Laravel throws, such as a list missing a key
     */
    public function outdated(): array;
}
