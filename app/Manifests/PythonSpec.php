<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\PackageSpec;
use App\Data\Package;

/**
 * A requirement for pipx or uv, such as black, black[d] or "ruff>=0.6". The name is normalized
 * the way pip compares names, so Black and black_ both match black. The version holds whatever
 * follows the name, extras included, and goes back on the install unchanged.
 */
final class PythonSpec implements PackageSpec
{
    /** A PEP 508 name, then optional [extras] and an optional version specifier. */
    private const ENTRY = '/^([A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?)((?:\[[^\]]*\])?\s*(?:[<>=!~].*)?)$/';

    /**
     * Lowercase with each run of -, _ and . turned into one -.
     */
    public static function normalized(string $name): string
    {
        return strtolower((string) preg_replace('/[-_.]+/', '-', $name));
    }

    /**
     * Whether an installed version meets a requirement's specifier, such as ">=0.6,<1" or
     * "[d]==24.1". A clause it can't read, such as ~= or an environment marker, passes.
     *
     * @param  string  $requirement  what parse() put in the version, extras included
     */
    public static function allows(string $requirement, string $version): bool
    {
        $specifier = (string) preg_replace('/^\[[^\]]*\]\s*/', '', $requirement);

        foreach (explode(',', $specifier) as $clause) {
            if (! preg_match('/^\s*(==|!=|>=|<=|>|<)\s*([0-9][0-9.]*?)(\.\*)?\s*$/', $clause, $match)) {
                continue;
            }

            [$wanted, $has] = [self::withoutTrailingZeros($match[2]), self::withoutTrailingZeros($version)];
            $met = match (true) {
                isset($match[3]) && $match[1] === '==' => $has === $wanted || str_starts_with($has, $wanted.'.'),
                isset($match[3]) && $match[1] === '!=' => $has !== $wanted && ! str_starts_with($has, $wanted.'.'),
                isset($match[3]) => true,
                default => version_compare($has, $wanted, $match[1]),
            };

            if (! $met) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return ?Package null for anything that isn't a plain requirement, such as a URL or a path
     */
    public function parse(string $entry): ?Package
    {
        if (! preg_match(self::ENTRY, $entry, $match)) {
            return null;
        }

        $rest = trim($match[2]);

        return new Package(self::normalized($match[1]), 'package', $rest === '' ? null : $rest);
    }

    /**
     * Such as black[d]==24.1.0, or the bare name.
     */
    public function argument(Package $package): string
    {
        return $package->name.($package->version ?? '');
    }

    /**
     * Just the normalized name, such as black. Extras are left off too, since they sit in the version.
     */
    public function entry(Package $package): string
    {
        return $package->name;
    }

    /**
     * 1.0 and 1.0.0 are the same release to pip.
     */
    private static function withoutTrailingZeros(string $version): string
    {
        return (string) preg_replace('/(\.0)+$/', '', $version);
    }
}
