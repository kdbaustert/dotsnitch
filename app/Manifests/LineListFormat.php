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
     * @param  PackageSpec  $spec  reads each entry on a line as a name and a version
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
     * Also how read() gets its entries, so reading and editing agree on what a line holds.
     */
    public function edit(string $contents): LineListEdit
    {
        return new LineListEdit($contents, $this->spec);
    }
}
