<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Support\AmbiguousHomebrew;
use App\Support\PathResolver;

use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

trait ChoosesHomebrew
{
    /**
     * Asks which Homebrew to use when both exist and nothing is saved yet. Without a terminal it
     * takes the first one PathResolver lists (Apple Silicon's) for this run and saves nothing.
     *
     * @return ?string null when Homebrew isn't installed
     */
    private function resolveHomebrewPrefix(PathResolver $paths): ?string
    {
        try {
            return $paths->homebrewPrefix();
        } catch (AmbiguousHomebrew $e) {
            if (! $this->input->isInteractive()) {
                $prefix = $e->prefixes[0];
                warning("Using the Homebrew in {$prefix} this run. Run dotsnitch in a terminal to pick from ".implode(', ', $e->prefixes).'.');
                $paths->useHomebrewPrefix($prefix);

                return $prefix;
            }

            $prefix = select('Which Homebrew should dotsnitch sync on this Mac?', $e->prefixes);
            $paths->chooseHomebrewPrefix($prefix);

            return $prefix;
        }
    }
}
