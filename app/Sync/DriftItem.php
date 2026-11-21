<?php

declare(strict_types=1);

namespace App\Sync;

use App\Data\Link;
use App\Data\Package;

final readonly class DriftItem
{
    /**
     * @param  string  $check  the SyncTarget key, such as "brew" or "symlinks"
     * @param  ?string  $detail  shown next to the item, such as brew's deprecation reason
     */
    public function __construct(
        public string $check,
        public DriftKind $kind,
        public Package|Link $subject,
        public ?string $detail = null,
    ) {}

    /**
     * Stays the same across runs, so ReportStore can tell which items are new.
     */
    public function key(): string
    {
        return "{$this->check}:{$this->kind->value}:{$this->subject->key()}";
    }
}
