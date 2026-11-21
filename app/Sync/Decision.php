<?php

declare(strict_types=1);

namespace App\Sync;

final readonly class Decision
{
    /**
     * @param  list<string>  $manifests  absolute paths: the one file to add to, or every file the
     *                                   check reads for a remove or replace
     */
    public function __construct(
        public DriftItem $item,
        public Action $action,
        public array $manifests = [],
    ) {}
}
