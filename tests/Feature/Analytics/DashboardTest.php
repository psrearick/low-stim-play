<?php

use App\Analytics\Models\AnalyticsDailyDimension;
use App\Analytics\Models\AnalyticsDailyTotal;
use App\Analytics\Models\AnalyticsEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['analytics.admin_emails' => 'admin@example.com']);
});

test('a guest is redirected, an authenticated non-admin gets 403, an admin gets 200', function () {
    $this->get('/analytics')->assertRedirect('/login');

    $nonAdmin = User::factory()->create(['email' => 'nobody@example.com', 'email_verified_at' => now()]);
    $this->actingAs($nonAdmin)->get('/analytics')->assertForbidden();

    $admin = User::factory()->create(['email' => 'admin@example.com', 'email_verified_at' => now()]);
    $this->actingAs($admin)->get('/analytics')->assertOk();
});

test('the dashboard renders correct figures from seeded rollups', function () {
    $admin = User::factory()->create(['email' => 'admin@example.com', 'email_verified_at' => now()]);
    $date = now()->subDay()->toDateString();

    AnalyticsDailyTotal::create(['date' => $date, 'pageviews' => 42, 'visitors' => 17]);
    AnalyticsDailyDimension::create(['date' => $date, 'dimension' => 'path', 'value' => '/', 'pageviews' => 42, 'visitors' => 17]);
    AnalyticsDailyDimension::create(['date' => $date, 'dimension' => 'country', 'value' => 'US', 'pageviews' => 30, 'visitors' => 10]);
    AnalyticsDailyDimension::create(['date' => $date, 'dimension' => 'region', 'value' => 'US-CA', 'pageviews' => 12, 'visitors' => 4]);
    AnalyticsDailyDimension::create(['date' => $date, 'dimension' => 'referrer', 'value' => 'google.com', 'pageviews' => 5, 'visitors' => 3]);

    $this->actingAs($admin)
        ->get('/analytics?range=7d')
        ->assertOk()
        ->assertSee('42')
        ->assertSee('17')
        ->assertSee('/')
        ->assertSee('United States')
        ->assertSee('CA, United States')
        ->assertSee('google.com');
});

test('visiting the dashboard creates no analytics events', function () {
    $admin = User::factory()->create(['email' => 'admin@example.com', 'email_verified_at' => now()]);

    $this->actingAs($admin)->get('/analytics')->assertOk();

    expect(AnalyticsEvent::count())->toBe(0);
});
