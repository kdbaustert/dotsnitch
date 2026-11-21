<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\ManifestFormat;
use App\Contracts\PackageSpec;
use App\Data\Package;
use App\Support\JsonFile;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * One section of a composer.json or package.json, such as "require" or "dependencies". Each
 * constraint comes back as the package's version.
 */
final class JsonRequireFormat implements ManifestFormat
{
    /**
     * @param  string  $section  such as "require" or "dependencies"
     */
    public function __construct(
        private readonly string $section,
        private readonly PackageSpec $spec,
    ) {}

    /**
     * Entries the spec doesn't take, such as "php" or "ext-json", are left out.
     *
     * @throws RuntimeException when the file isn't a JSON object or the section isn't one
     */
    public function read(string $path): Collection
    {
        $entries = JsonFile::read($path)[$this->section] ?? [];

        if (! is_array($entries)) {
            throw new RuntimeException("\"{$this->section}\" in ".basename($path)." isn't an object");
        }

        $packages = collect();

        foreach ($entries as $name => $constraint) {
            $package = $this->spec->parse((string) $name);

            if ($package !== null) {
                $packages->push(new Package($package->name, $package->type, is_string($constraint) ? $constraint : null));
            }
        }

        return $packages;
    }

    /**
     * @throws RuntimeException when the text isn't a JSON object
     */
    public function edit(string $contents): JsonRequireEdit
    {
        return new JsonRequireEdit($contents, $this->section);
    }
}
