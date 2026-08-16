<?php

namespace App\Analytics\Geo;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use MaxMind\Db\Reader\InvalidDatabaseException;
use Throwable;

/**
 * Resolves a request's IP to a country code. 'maxmind' | 'none', selected
 * by config('analytics.geo.driver') -- see docs/DEVLOG.md's Analytics
 * section for why there is no 'header' driver despite
 * docs/specs/analytics.md describing one.
 *
 * Bound as a singleton in AppServiceProvider so the mmdb file is only
 * opened once per worker, not once per request -- same reasoning as
 * App\Services\Boggle\Solver's word-trie singleton.
 */
class CountryResolver
{
    private ?Reader $reader = null;

    private bool $readerLoadAttempted = false;

    public function resolve(?string $ip): ?string
    {
        if ($ip === null || config('analytics.geo.driver') !== 'maxmind') {
            return null;
        }

        $reader = $this->reader();

        if ($reader === null) {
            return null;
        }

        try {
            return $reader->country($ip)->country->isoCode;
        } catch (AddressNotFoundException|InvalidDatabaseException) {
            return null;
        }
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
