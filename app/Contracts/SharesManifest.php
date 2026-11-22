<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A check whose patterns match a file other checks read too, such as the mas lines of a Brewfile.
 */
interface SharesManifest
{
    /**
     * False for a matched file that holds nothing for this check. It's then left out, so a
     * Brewfile with no mas line doesn't turn every App Store app into an untracked one.
     *
     * @param  string  $path  absolute
     */
    public function hasEntriesIn(string $path): bool;
}
