<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Every file one check's patterns matched, split by role. Paths are relative to the repo and sorted.
 */
final readonly class ManifestMatches
{
    /**
     * @param  list<string>  $bases  exact or glob matches, such as Brewfile
     * @param  list<string>  $hostOverlays  suffixed with this Mac's hostname, always merged in
     * @param  list<string>  $variants  any other suffix (Brewfile.work), merged only if the user opts in
     */
    public function __construct(
        public array $bases = [],
        public array $hostOverlays = [],
        public array $variants = [],
    ) {}

    /**
     * True when the repo has no manifest at all for this check.
     */
    public function isEmpty(): bool
    {
        return $this->bases === [] && $this->hostOverlays === [] && $this->variants === [];
    }
}
