<?php

declare(strict_types=1);

namespace App\Sync;

/**
 * How sync asks about the drift it found.
 */
enum ResolutionMode: string
{
    case Review = 'review';
    case Each = 'each';
    case Report = 'report';

    /**
     * As the menu after the report shows it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Review => 'Quick review (two prompts per group)',
            self::Each => 'Item by item',
            self::Report => 'Report only, change nothing',
        };
    }
}
