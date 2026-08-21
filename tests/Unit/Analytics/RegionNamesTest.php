<?php

use App\Analytics\Geo\RegionNames;

test('label formats a composite ISO 3166-2 value as "subdivision, country name"', function () {
    expect(RegionNames::label('US-CA'))->toBe('CA, United States');
});

test('label falls back to just the country name when there is no subdivision part', function () {
    expect(RegionNames::label('US'))->toBe('United States');
});

test('label returns Unknown for null or empty', function () {
    expect(RegionNames::label(null))->toBe('Unknown')
        ->and(RegionNames::label(''))->toBe('Unknown');
});
