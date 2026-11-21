<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Sync\DriftItem;

/**
 * A check that offers Show diff. The command prints the diff and asks the same question again.
 */
interface ShowsDiff
{
    /**
     * @return string the diff, or why there isn't one
     */
    public function diff(DriftItem $item): string;
}
