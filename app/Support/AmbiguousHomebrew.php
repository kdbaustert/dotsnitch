<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Both Homebrew prefixes exist and the user hasn't picked one. The command asks, then calls
 * PathResolver::chooseHomebrewPrefix().
 */
final class AmbiguousHomebrew extends RuntimeException
{
    /**
     * @param  list<string>  $prefixes
     */
    public function __construct(public readonly array $prefixes)
    {
        parent::__construct('Found more than one Homebrew: '.implode(', ', $prefixes));
    }
}
