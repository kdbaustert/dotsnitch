<?php

declare(strict_types=1);

namespace App\Sync;

/**
 * A decision with the steps that carry it out, ready to show in the plan and run.
 */
final readonly class Change
{
    /**
     * @param  list<Step>  $steps  in the order they run; the first failure stops the rest
     */
    public function __construct(
        public Decision $decision,
        public array $steps,
    ) {}
}
