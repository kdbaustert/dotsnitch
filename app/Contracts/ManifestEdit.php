<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Package;

/**
 * One manifest's text, open for editing. Lines it doesn't touch stay exactly as written,
 * comments included.
 */
interface ManifestEdit
{
    /**
     * Does nothing when the package is already listed.
     */
    public function add(Package $package): void;

    /**
     * @return bool false when no entry for this name was found
     */
    public function remove(Package $package): bool;

    /**
     * Swaps the entry in place, or drops it when $new is already listed.
     *
     * @return bool false when no entry for $old was found
     */
    public function replace(Package $old, Package $new): bool;

    /**
     * The edited text, ready to write back.
     */
    public function contents(): string;
}
