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
}
