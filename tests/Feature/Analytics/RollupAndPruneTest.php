<?php

use App\Analytics\Models\AnalyticsDailyDimension;
use App\Analytics\Models\AnalyticsDailyTotal;
use App\Analytics\Models\AnalyticsEvent;
use App\Analytics\Models\AnalyticsSalt;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedAnalyticsEvent(string $date, array $overrides = []): AnalyticsEvent
{
    return AnalyticsEvent::create(array_merge([
        'date' => $date,
        'hour' => 12,
        'visitor_hash' => 'hash-'.uniqid(),
        'path' => '/',
        'country' => 'US',
        'region' => null,
        'referrer_host' => null,
        'utm_source' => null,
        'created_at' => $date.' 12:00:00',
    ], $overrides));
}

test('rollup produces correct totals and per-dimension rows for a seeded day', function () {
    $date = now()->subDay()->toDateString();

    seedAnalyticsEvent($date, ['visitor_hash' => 'a', 'path' => '/', 'country' => 'US', 'region' => 'US-CA', 'referrer_host' => 'google.com']);
    seedAnalyticsEvent($date, ['visitor_hash' => 'a', 'path' => '/', 'country' => 'US', 'region' => 'US-CA', 'referrer_host' => 'google.com']);
    seedAnalyticsEvent($date, ['visitor_hash' => 'b', 'path' => '/about', 'country' => 'CA', 'region' => null, 'referrer_host' => null]);

    $this->artisan('analytics:rollup', ['--date' => $date])->assertSuccessful();

    $total = AnalyticsDailyTotal::where('date', $date)->first();
    expect($total->pageviews)->toBe(3);
    expect($total->visitors)->toBe(2);

    $paths = AnalyticsDailyDimension::where('date', $date)->where('dimension', 'path')->get()->keyBy('value');
    expect($paths['/']->pageviews)->toBe(2);
    expect($paths['/']->visitors)->toBe(1);
    expect($paths['/about']->pageviews)->toBe(1);

    $countries = AnalyticsDailyDimension::where('date', $date)->where('dimension', 'country')->get()->keyBy('value');
    expect($countries['US']->pageviews)->toBe(2);
    expect($countries['CA']->pageviews)->toBe(1);

    // The one event with no region resolved (country = 'CA') doesn't
    // produce a null/empty regions row -- rollupDimension() always
    // whereNotNull()s its column, same as every other dimension.
    $regions = AnalyticsDailyDimension::where('date', $date)->where('dimension', 'region')->get()->keyBy('value');
    expect($regions)->toHaveCount(1);
    expect($regions['US-CA']->pageviews)->toBe(2);
    expect($regions['US-CA']->visitors)->toBe(1);

    $referrers = AnalyticsDailyDimension::where('date', $date)->where('dimension', 'referrer')->get();
    expect($referrers)->toHaveCount(1);
    expect($referrers->first()->value)->toBe('google.com');
    expect($referrers->first()->pageviews)->toBe(2);
});

test('rollup run twice for the same date does not duplicate rows', function () {
    $date = now()->subDay()->toDateString();

    seedAnalyticsEvent($date, ['path' => '/']);
    seedAnalyticsEvent($date, ['path' => '/about']);

    $this->artisan('analytics:rollup', ['--date' => $date])->assertSuccessful();
    $this->artisan('analytics:rollup', ['--date' => $date])->assertSuccessful();

    expect(AnalyticsDailyTotal::where('date', $date)->count())->toBe(1);
    expect(AnalyticsDailyDimension::where('date', $date)->where('dimension', 'path')->count())->toBe(2);
});

test('prune deletes events past retention and salts past 48 hours, and leaves rollups untouched', function () {
    $oldDate = now()->subDays((int) config('analytics.retention.raw_days') + 1)->toDateString();
    $recentDate = now()->toDateString();

    seedAnalyticsEvent($oldDate);
    seedAnalyticsEvent($recentDate);

    AnalyticsDailyTotal::create(['date' => $oldDate, 'pageviews' => 1, 'visitors' => 1]);
    AnalyticsDailyDimension::create(['date' => $oldDate, 'dimension' => 'path', 'value' => '/', 'pageviews' => 1, 'visitors' => 1]);

    $oldSalt = AnalyticsSalt::create(['date' => now()->subDays(3)->toDateString(), 'salt' => 'old']);
    $oldSalt->forceFill(['created_at' => now()->subHours(49)])->save();
    $freshSalt = AnalyticsSalt::create(['date' => $recentDate, 'salt' => 'fresh']);

    $this->artisan('analytics:prune')->assertSuccessful();

    expect(AnalyticsEvent::where('date', $oldDate)->exists())->toBeFalse();
    expect(AnalyticsEvent::where('date', $recentDate)->exists())->toBeTrue();

    expect(AnalyticsSalt::find($oldSalt->id))->toBeNull();
    expect(AnalyticsSalt::find($freshSalt->id))->not->toBeNull();

    // Rollups are kept indefinitely -- pruning only ever touches raw
    // events and salts, per docs/specs/analytics.md section 5.3.
    expect(AnalyticsDailyTotal::where('date', $oldDate)->exists())->toBeTrue();
    expect(AnalyticsDailyDimension::where('date', $oldDate)->exists())->toBeTrue();
});
