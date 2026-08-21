<?php

namespace App\Analytics\Geo;

/**
 * Display labels for the dashboard's regions panel. `value` there is the
 * ISO 3166-2 composite App\Analytics\Geo\CountryResolver::resolveRegion()
 * writes (e.g. "US-CA") -- unlike CountryNames, this deliberately does
 * NOT try to spell out the subdivision itself via ICU: PHP's intl
 * extension has no display-name support for subdivisions (confirmed
 * live -- Locale::getDisplayRegion() with a BCP47 "-u-sd-" subdivision
 * extension silently ignores it and returns just the country name), only
 * for top-level countries/regions, and MaxMind's GeoLite2 doesn't ship a
 * general subdivision-code-to-name table either. Rather than hand-
 * maintaining one (the exact kind of ~250+-entry static array
 * CountryNames's own docblock already rejected doing for countries, and
 * subdivisions are a much larger set), the raw code plus the country's
 * own full name is shown instead -- e.g. "CA, United States" -- which is
 * enough to actually identify a US state and still legible for anywhere
 * else, without ever risking a wrong or made-up name.
 */
class RegionNames
{
    public static function label(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'Unknown';
        }

        [$country, $subdivision] = array_pad(explode('-', $value, 2), 2, null);

        if ($subdivision === null) {
            return CountryNames::name($country);
        }

        return "{$subdivision}, ".CountryNames::name($country);
    }
}
