<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\ManifestFormat;
use App\Contracts\PackageSpec;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A plain list like npm-global.txt or a Composerfile: one package per line, # for comments.
 */
final class LineListFormat implements ManifestFormat
{
    /**
     * The spec decides how an entry splits into name and version.
     */
    public function __construct(
        private readonly PackageSpec $spec,
    ) {}

    /**
     * @throws RuntimeException when the file isn't there
     */
    public function read(string $path): Collection
    {
        if (! is_file($path)) {
            throw new RuntimeException("Can't read {$path}");
        }

        return $this->edit((string) file_get_contents($path))->entries();
    }

    /**
     * Opens the text for line edits.
     */
    public function edit(string $contents): LineListEdit
    {
        return new LineListEdit($contents, $this->spec);
    }
}
