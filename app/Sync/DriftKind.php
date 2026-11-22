<?php

declare(strict_types=1);

namespace App\Sync;

enum DriftKind: string
{
    case Missing = 'missing';
    case Untracked = 'untracked';
    case Version = 'version';
    case Deprecated = 'deprecated';
    case Disabled = 'disabled';
    case NotLinked = 'not-linked';
    case Conflict = 'conflict';
    case WrongTarget = 'wrong-target';
    case Broken = 'broken';
    case Uncommitted = 'uncommitted';
    case Unpushed = 'unpushed';
    case Behind = 'behind';
    case Differs = 'differs';

    /**
     * What the drift list and the prompts call it. "missing" alone doesn't say which side lacks
     * the package, and the value has to stay as it is for saved scans and sync records.
     */
    public function label(): string
    {
        return $this === self::Missing ? 'not installed' : $this->value;
    }
}
