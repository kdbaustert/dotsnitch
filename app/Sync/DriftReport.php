<?php

declare(strict_types=1);

namespace App\Sync;

final readonly class DriftReport
{
    /**
     * @param  list<DriftItem>  $items
     * @param  int  $ignored  items IgnoreList took out, so the report can say they're hidden
     */
    public function __construct(
        public string $check,
        public array $items = [],
        public int $ignored = 0,
    ) {}

    /**
     * True with nothing left to resolve. Ignored items don't count, so "In sync (2 ignored)" is
     * possible.
     */
    public function isInSync(): bool
    {
        return $this->items === [];
    }

    /**
     * @return list<DriftItem>
     */
    public function itemsOfKind(DriftKind $kind): array
    {
        return array_values(array_filter($this->items, fn (DriftItem $item) => $item->kind === $kind));
    }
}
