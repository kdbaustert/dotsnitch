<?php

declare(strict_types=1);

namespace App\Support;

final class LocalTimezone
{
    /**
     * The zone the Mac's clock shows. PHP doesn't look at /etc/localtime, and Laravel defaults to
     * UTC, so "new since 09:00" would be hours off without this.
     *
     * @param  string  $link  a symlink into a zoneinfo directory
     * @return string such as "America/New_York", or UTC when the link isn't there or isn't a zone
     */
    public static function fromLink(string $link): string
    {
        $target = is_link($link) ? (string) readlink($link) : '';

        if (preg_match('#/zoneinfo/(.+)$#', $target, $match) === 1 && in_array($match[1], timezone_identifiers_list(), true)) {
            return $match[1];
        }

        return 'UTC';
    }
}
