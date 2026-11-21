<?php

declare(strict_types=1);

namespace App\Data;

final readonly class Package
{
    /**
     * @param  string  $type  manager-specific kind, such as "formula", "cask" or "tap"
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $version = null,
    ) {}

    /**
     * The identity used for diffing and ignores. Version is left out so a pin isn't a different package.
     */
    public function key(): string
    {
        return "{$this->type}:{$this->name}";
    }
}
