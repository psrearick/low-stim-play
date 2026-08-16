<?php

namespace App\Analytics\Geo;

use Locale;

/**
 * Full country names for the dashboard's countries panel -- "full names
 * via a static ISO map, not flag emoji" per docs/specs/analytics.md
 * section 9.3 (flags are a poor accessibility choice, and this app
 * avoids emoji generally). Backed by ICU's built-in region data (PHP's
 * intl extension, already required by this app) rather than a
 * hand-maintained ~250-entry array.
 */
class CountryNames
{
    public static function name(?string $code): string
    {
        if ($code === null || $code === '') {
            return 'Unknown';
        }

        if (! class_exists(Locale::class)) {
            return $code;
        }

        $name = Locale::getDisplayRegion("und-{$code}", 'en');

        return $name !== '' && $name !== $code ? $name : $code;
    }
}
