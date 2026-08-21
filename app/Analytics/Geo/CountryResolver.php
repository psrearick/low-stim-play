<?php

namespace App\Analytics\Geo;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use GeoIp2\Model\City;
use MaxMind\Db\Reader\InvalidDatabaseException;
use Throwable;

/**
 * Resolves a request's IP to a country code and, when available, a region
 * (state/province) code. 'maxmind' | 'none', selected by
 * config('analytics.geo.driver') -- see docs/DEVLOG.md's Analytics section
 * for why there is no 'header' driver despite docs/specs/analytics.md
 * describing one.
 *
 * Reads the GeoLite2-City edition (not Country) -- City's schema is a
 * superset of Country's (GeoIp2\Model\City extends GeoIp2\Model\Country),
 * so country resolution is unaffected by this, and it's the only edition
 * with subdivision (state/region) data at all. See
 * App\Analytics\Console\Commands\AnalyticsGeoipUpdate, which downloads
 * this same edition, and config('analytics.geo.database_path')'s default.
 *
 * Bound as a singleton in AppServiceProvider so the mmdb file is only
 * opened once per worker, not once per request -- same reasoning as
 * App\Services\Boggle\Solver's word-trie singleton.
 */
class CountryResolver
{
    private ?Reader $reader = null;

    private bool $readerLoadAttempted = false;

    /**
     * Last-lookup memoization, keyed by IP -- resolve() and
     * resolveRegion() are both called once per request in
     * App\Analytics\Http\Middleware\RecordPageview, for the same IP, and
     * without this each call would independently hit the mmdb reader.
     * Deliberately just the single most recent IP, not a growing cache --
     * this singleton lives for a whole worker's lifetime, and every
     * request's own pair of calls always shares one IP.
     */
    private ?string $lastIp = null;

    private ?City $lastCity = null;

    public function resolve(?string $ip): ?string
    {
        return $this->city($ip)?->country->isoCode;
    }

    /**
     * ISO 3166-2 composite, e.g. "US-CA" -- country + subdivision code,
     * not the subdivision code alone (a bare "CA" is ambiguous: California
     * vs a province elsewhere). `null` when the IP has no country, no
     * subdivision data (GeoLite2's free dataset doesn't have subdivisions
     * for every country), or can't be resolved at all.
     */
    public function resolveRegion(?string $ip): ?string
    {
        $city = $this->city($ip);

        if ($city === null) {
            return null;
        }

        $country = $city->country->isoCode;
        $subdivision = $city->mostSpecificSubdivision->isoCode;

        if ($country === null || $subdivision === null) {
            return null;
        }

        return "{$country}-{$subdivision}";
    }

    private function city(?string $ip): ?City
    {
        if ($ip === null || config('analytics.geo.driver') !== 'maxmind') {
            return null;
        }

        if ($ip === $this->lastIp) {
            return $this->lastCity;
        }

        $reader = $this->reader();
        $city = null;

        if ($reader !== null) {
            try {
                $city = $reader->city($ip);
            } catch (AddressNotFoundException|InvalidDatabaseException) {
                $city = null;
            }
        }

        $this->lastIp = $ip;
        $this->lastCity = $city;

        return $city;
    }

    private function reader(): ?Reader
    {
        if ($this->readerLoadAttempted) {
            return $this->reader;
        }

        $this->readerLoadAttempted = true;

        /** @var mixed $path */
        $path = config('analytics.geo.database_path');

        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        try {
            $this->reader = new Reader($path);
        } catch (Throwable) {
            $this->reader = null;
        }

        return $this->reader;
    }
}
