<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Sync\DriftItem;

/**
 * A check that can say what the dotfiles and this Mac each have for an item. Sync lists both
 * under the item.
 */
interface ComparesSides
{
    /**
     * @param  DriftItem  $item  from the last scan()
     * @return array{dotfiles: string, mac: string} such as "listed in Brewfile" and "not installed"
     */
    public function sides(DriftItem $item): array;
}
