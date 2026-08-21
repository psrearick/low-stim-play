<?php

use App\Analytics\Geo\CountryResolver;
use Tests\TestCase;

uses(TestCase::class);

test('resolve and resolveRegion both return null for a null IP', function () {
    $resolver = new CountryResolver;

    expect($resolver->resolve(null))->toBeNull()
        ->and($resolver->resolveRegion(null))->toBeNull();
});

test('resolve and resolveRegion both return null when the geo driver is not maxmind', function () {
    config(['analytics.geo.driver' => 'none']);
    $resolver = new CountryResolver;

    expect($resolver->resolve('8.8.8.8'))->toBeNull()
        ->and($resolver->resolveRegion('8.8.8.8'))->toBeNull();
});

test('resolve and resolveRegion both return null when the configured mmdb file does not exist', function () {
    config([
        'analytics.geo.driver' => 'maxmind',
        'analytics.geo.database_path' => storage_path('app/geoip/does-not-exist.mmdb'),
    ]);
    $resolver = new CountryResolver;

    expect($resolver->resolve('8.8.8.8'))->toBeNull()
        ->and($resolver->resolveRegion('8.8.8.8'))->toBeNull();
});
