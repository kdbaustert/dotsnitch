<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Package;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Reads and edits one kind of manifest file.
 */
interface ManifestFormat
{
    /**
     * @return Collection<int, Package> in file order
     *
     * @throws RuntimeException when the file can't be read or parsed
     */
    public function read(string $path): Collection;

    /**
     * Opens a manifest's text for line edits. The caller reads and writes the file.
     */
    public function edit(string $contents): ManifestEdit;
}
