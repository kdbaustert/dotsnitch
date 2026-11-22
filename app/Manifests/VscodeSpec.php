<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\PackageSpec;
use App\Data\Package;

/**
 * A VS Code extension as publisher.name, with an optional @version the way
 * `code --install-extension` takes it. Ids are lowercased, since VS Code ignores their case.
 */
final class VscodeSpec implements PackageSpec
{
    private const ENTRY = '/^([a-z0-9][a-z0-9-]*\.[a-z0-9][\w-]*)(?:@(.+))?$/i';

    /**
     * @return ?Package null for anything that isn't publisher.name
     */
    public function parse(string $entry): ?Package
    {
        return preg_match(self::ENTRY, $entry, $match) ? new Package(strtolower($match[1]), 'extension', $match[2] ?? null) : null;
    }

    /**
     * Such as editorconfig.editorconfig@0.16.4, or the bare id.
     */
    public function argument(Package $package): string
    {
        return $package->version === null ? $package->name : "{$package->name}@{$package->version}";
    }

    /**
     * Just the id.
     */
    public function entry(Package $package): string
    {
        return $package->name;
    }
}
