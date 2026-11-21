<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\ManifestFormat;
use App\Contracts\PackageSpec;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A setup script that keeps its packages in a bash array (see BashArrayEdit).
 */
final class BashArrayFormat implements ManifestFormat
{
    /**
     * The spec decides how an item splits into name and version.
     */
    public function __construct(
        private readonly PackageSpec $spec,
    ) {}

    /**
     * @throws RuntimeException when the file isn't there or has no array, since then every
     *                          package would show as untracked
     */
    public function read(string $path): Collection
    {
        if (! is_file($path)) {
            throw new RuntimeException("Can't read {$path}");
        }

        $edit = $this->edit((string) file_get_contents($path));

        if (! $edit->hasArray()) {
            throw new RuntimeException(basename($path).' has no bash array, like name=( ... ), to read packages from');
        }

        return $edit->entries();
    }

    /**
     * Opens the script for edits inside its arrays.
     */
    public function edit(string $contents): BashArrayEdit
    {
        return new BashArrayEdit($contents, $this->spec);
    }
}
