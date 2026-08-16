<?php

return [
    'enabled' => env('ANALYTICS_ENABLED', true),

    // 'after_response' writes post-response with no worker needed.
    // 'queue' requires a running queue worker (this app has one -- see
    // composer.json's "dev" script and QUEUE_CONNECTION=redis in .env --
    // but after_response is still the safer default for a fresh
    // environment that hasn't provisioned a worker yet).
    'dispatch_mode' => env('ANALYTICS_DISPATCH', 'after_response'),
    'queue_name' => env('ANALYTICS_QUEUE', 'default'),

    // Paths excluded from tracking. Supports wildcards via Str::is().
    // This app has no admin/api/telescope/horizon/debugbar surface, and
    // Livewire's own update/upload-file endpoints are POST-only (already
    // excluded by the middleware's GET-only rule) behind a per-app hashed
    // prefix rather than a stable "livewire/*" path, so neither needs an
    // entry here.
    'except' => [
        'analytics',
        'analytics/*',
        'up',
    ],

    'respect_dnt' => env('ANALYTICS_RESPECT_DNT', false),

    // 'maxmind' | 'none'. No 'header' driver: this app isn't confirmed to
    // sit behind a proxy that sets a country header on Laravel Cloud, and
    // depending on one that could silently disappear later was judged not
    // worth it -- see docs/DEVLOG.md's Analytics section.
    'geo' => [
        'driver' => env('ANALYTICS_GEO_DRIVER', 'maxmind'),
        'database_path' => env('ANALYTICS_GEO_DB', storage_path('app/geoip/GeoLite2-Country.mmdb')),
    ],

    'retention' => [
        'raw_days' => env('ANALYTICS_RAW_RETENTION', 60),
        'salt_hours' => 48,
        // Rollups are kept indefinitely.
    ],

    // Comma-separated allow-list checked by the 'view-analytics' gate
    // (App\Providers\AppServiceProvider::boot()) -- this app has no
    // is_admin column or other admin concept, so this is the whole
    // access-control story for the dashboard.
    'admin_emails' => env('ANALYTICS_ADMIN_EMAILS', ''),

    'dashboard' => [
        'middleware' => ['web', 'auth', 'verified'],
        'gate' => 'view-analytics',
        'timezone' => env('ANALYTICS_TIMEZONE', 'UTC'),
    ],
];
