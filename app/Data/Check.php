<?php

declare(strict_types=1);

namespace App\Data;

use App\Contracts\ScansWholeRepo;
use App\Contracts\SyncTarget;

/**
 * A registered check as sync sees it on this Mac: whether it can run and what it would read.
 */
final readonly class Check
{
    /**
     * @param  list<string>  $manifests  repo-relative, base first
     */
    public function __construct(
        public SyncTarget $target,
        public array $manifests,
        public bool $available,
    ) {}

    /**
     * The target's key, which is what --checks and the saved selection hold.
     */
    public function key(): string
    {
        return $this->target->key();
    }

    /**
     * Ticked by default: it can run and there's a manifest to compare against, or it reads the
     * whole repo.
     */
    public function isReady(): bool
    {
        return $this->available && ($this->manifests !== [] || $this->target instanceof ScansWholeRepo);
    }

    /**
     * Such as "Homebrew (Brewfile, Brewfile.kennys-mbp)", "Cargo (not installed)" or
     * "Symlinked config files (whole repo)".
     */
    public function choiceLabel(): string
    {
        $state = match (true) {
            ! $this->available => 'not installed',
            $this->manifests === [] && $this->target instanceof ScansWholeRepo => 'whole repo',
            $this->manifests === [] => 'no manifest found',
            default => implode(', ', array_map(basename(...), $this->manifests)),
        };

        return "{$this->target->label()} ({$state})";
    }
}
