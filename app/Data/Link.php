<?php

declare(strict_types=1);

namespace App\Data;

final readonly class Link
{
    /**
     * @param  string  $source  absolute path inside the dotfiles repo
     * @param  string  $target  absolute path in $HOME where the symlink belongs
     */
    public function __construct(
        public string $source,
        public string $target,
    ) {}

    /**
     * Keyed by target, since one path in $HOME can only hold one link.
     */
    public function key(): string
    {
        return $this->target;
    }
}
