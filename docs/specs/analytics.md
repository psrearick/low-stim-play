# Spec: First-party cookieless analytics for a Laravel app

**Target environment:** Laravel 11/12 on Laravel Cloud, MySQL or Postgres, Redis cache.
**Deliverable:** An in-app analytics subsystem (middleware + jobs + models + console commands) and an authenticated dashboard at `/analytics`.

Everything lives inside the host app under `app/Analytics/`. Do not build this as a composer package.

> **Implemented.** This app is actually Laravel 13 + Livewire 4, and several specifics below were deliberately adapted rather than followed literally — Livewire 4 single-file "page" components instead of Livewire 3, an inline SVG chart instead of Chart.js, a MaxMind-only geo driver (no `header` driver), and an `ANALYTICS_ADMIN_EMAILS` env allow-list instead of an `is_admin` column. See `docs/DEVLOG.md`'s "Analytics" section for the full list of deviations and why, and `docs/TODO.md` for what's still outstanding (a real deploy-time verification pass, MaxMind credentials, and a privacy-policy page to hold section 12's copy).

---

## 1. Goals

Answer these questions for the site owner:

- Daily unique visitors, pageviews, and views per visitor
- Pageviews and unique visitors per page
- Visitor counts by country
- Top referrers and UTM sources
- A visitors-over-time chart for a selectable date range
- Live count of visitors in the last 30 minutes

## 2. Non-goals

Do not build any of the following. Each one is either a privacy problem or scope creep:

- Cross-session or cross-day visitor identity, user IDs, or any join between analytics rows and the `users` table
- Funnels, cohorts, session replay, heatmaps, per-user event streams
- City-level or lat/long geolocation. Country only.
- Any third-party analytics SDK or outbound request carrying visitor data
- Bounce rate and session duration (both require session identity we are deliberately not creating)

## 3. Privacy invariants

These are the constraints that make the system consent-exempt. Treat them as tests, not aspirations. If an implementation choice conflicts with one of these, the invariant wins.

1. **Nothing is written to or read from the visitor's device.** No cookies, no localStorage, no sessionStorage, no `Set-Cookie` on tracked responses, no canvas or font fingerprinting, no client-side script at all.
2. **Raw IP addresses are never persisted.** An IP may exist in memory during a request to compute a hash and a country code. It must not reach the database, a log line, a queue payload, or an exception report.
3. **Visitor hashes are unlinkable across days.** The salt rotates daily and old salts are destroyed, so yesterday's hash cannot be matched to today's.
4. **Salts are irrecoverable once pruned.** Salts older than 48 hours are hard-deleted. After that, the hashes derived from them are anonymous data with no key in existence.
5. **No cross-site tracking.** Data is first-party, used only for this site's own audience measurement, never shared or sold.

Add a short section to the app's privacy policy describing this. Draft copy is in section 12.

Note for the implementer: because of invariant 3, a unique-visitor count for a multi-day range is mathematically impossible to compute correctly. See section 8.3 — this constrains the dashboard and must not be papered over.

---

## 4. Configuration

Create `config/analytics.php`:

```php
return [
    'enabled' => env('ANALYTICS_ENABLED', true),

    // 'after_response' writes post-response with no worker needed.
    // 'queue' requires a running queue worker.
    'dispatch_mode' => env('ANALYTICS_DISPATCH', 'after_response'),
    'queue_name' => env('ANALYTICS_QUEUE', 'default'),

    // Paths excluded from tracking. Supports wildcards via Str::is().
    'except' => [
        'analytics',
        'analytics/*',
        'admin',
        'admin/*',
        'api/*',
        'up',
        'health',
        'horizon/*',
        'telescope/*',
        '_debugbar/*',
        'livewire/*',
    ],

    'respect_dnt' => env('ANALYTICS_RESPECT_DNT', false),

    'geo' => [
        // 'header' | 'maxmind' | 'none'
        'driver' => env('ANALYTICS_GEO_DRIVER', 'header'),
        'header' => env('ANALYTICS_GEO_HEADER', 'CF-IPCountry'),
        'database_path' => env('ANALYTICS_GEO_DB', storage_path('app/geoip/GeoLite2-Country.mmdb')),
    ],

    'retention' => [
        'raw_days' => env('ANALYTICS_RAW_RETENTION', 60),
        'salt_hours' => 48,
        // Rollups are kept indefinitely.
    ],

    'dashboard' => [
        'middleware' => ['web', 'auth'],
        'gate' => 'view-analytics',
        'timezone' => env('ANALYTICS_TIMEZONE', 'UTC'),
    ],
];
```

`dispatch_mode` defaults to `after_response` so the feature works on a fresh Laravel Cloud app with no worker provisioned. If a worker exists, `queue` is preferable.

---

## 5. Schema

Four migrations.

### 5.1 `analytics_salts`

```php
$table->id();
$table->date('date')->unique();
$table->string('salt', 64);
$table->timestamps();
```

Rows older than `retention.salt_hours` are deleted by the prune command. This table is the reason the hashes are anonymous; losing it is a feature.

### 5.2 `analytics_events` (raw, short-lived)

```php
$table->id();
$table->date('date');
$table->unsignedTinyInteger('hour');            // 0-23, UTC
$table->char('visitor_hash', 64);
$table->string('path', 255);                    // normalized, see 6.4
$table->string('route_name', 128)->nullable();
$table->char('country', 2)->nullable();         // ISO 3166-1 alpha-2
$table->string('referrer_host', 128)->nullable();
$table->string('utm_source', 64)->nullable();
$table->string('utm_medium', 64)->nullable();
$table->string('utm_campaign', 64)->nullable();
$table->timestamp('created_at')->nullable();

$table->index(['date', 'path']);
$table->index(['date', 'visitor_hash']);
$table->index(['created_at']);                  // for the live-visitors query
```

No `updated_at`, no soft deletes. Rows are insert-only.

### 5.3 Rollup tables

`analytics_daily_totals`

```php
$table->date('date')->primary();
$table->unsignedInteger('pageviews');
$table->unsignedInteger('visitors');
$table->timestamps();
```

`analytics_daily_dimensions`

```php
$table->id();
$table->date('date');
$table->string('dimension', 24);   // 'path' | 'country' | 'referrer' | 'utm_source'
$table->string('value', 255);
$table->unsignedInteger('pageviews');
$table->unsignedInteger('visitors');
$table->timestamps();

$table->unique(['date', 'dimension', 'value']);
$table->index(['dimension', 'date']);
```

One table for all four breakdowns keeps the query layer small. If a breakdown grows past a few thousand distinct values per day (referrer spam is the usual culprit), cap it: keep the top 500 by pageviews and fold the remainder into a single `(other)` row.

---

## 6. Recording pipeline

### 6.1 Trusted proxies (do this first, verify it works)

On Laravel Cloud the app sits behind a load balancer. If `TrustProxies` is not configured, `$request->ip()` returns the balancer's address and **every visitor hashes identically**, silently reporting one unique visitor per day forever.

In `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: '*');
})
```

Then add an artisan command `analytics:doctor` that dumps, for the current request context, the resolved client IP, whether it differs from `REMOTE_ADDR`, all `X-Forwarded-*` headers, and any header matching `/country/i`. The developer runs this against a deployed URL before trusting any numbers. Ship this command; it is the acceptance gate for section 6 and section 7.

### 6.2 Middleware

`App\Analytics\Http\Middleware\RecordPageview`, appended to the `web` group.

Skip the request entirely if any of these hold:

- `config('analytics.enabled')` is false
- Method is not GET
- Response status is not 2xx or 3xx
- Response `Content-Type` is not `text/html`
- The request is an AJAX, Livewire, Inertia partial, or prefetch request (`X-Requested-With`, `X-Livewire`, `X-Inertia`, `Purpose: prefetch`, `Sec-Purpose: prefetch`)
- Path matches `config('analytics.except')`
- `respect_dnt` is on and `DNT: 1` is present
- The user agent is a bot (section 6.5)
- The user agent is empty

Work happens in `terminate()`, not `handle()`, so nothing is added to response latency.

### 6.3 Visitor hash

```php
$salt = $this->salts->forToday();   // cached, see below

$visitorHash = hash('sha256', implode('|', [
    $salt,
    $request->ip(),
    (string) $request->userAgent(),
    $request->getHost(),
]));
```

`SaltRepository::forToday()` reads through Redis (`analytics:salt:{Y-m-d}`, TTL 25 hours) to the `analytics_salts` table, and creates the row with `Str::random(64)` on a miss. Use `firstOrCreate` on the unique `date` column so concurrent requests at midnight cannot produce two salts for one day. Never log the salt.

The IP and user agent must not be stored on the `PageviewData` DTO or in the job payload. Hash in the middleware, pass only the hash forward. A queued job serializes its payload to Redis or the database, so an IP in the payload is an IP at rest.

### 6.4 Path normalization

Prefer the matched route's URI so dynamic segments collapse:

```php
$path = $request->route()?->uri();     // e.g. 'posts/{slug}'
$path = $path ? '/'.ltrim($path, '/') : '/'.trim($request->path(), '/');
$path = $path === '//' ? '/' : rtrim($path, '/') ?: '/';
$path = Str::limit($path, 255, '');
```

Always drop the query string. Capture only `utm_source`, `utm_medium`, and `utm_campaign` from it, each truncated to 64 characters and lowercased.

Referrer: parse with `parse_url()`, take the host only, strip a leading `www.`, lowercase it, and null it out when the host equals the app's own host (that is internal navigation, not a referral).

### 6.5 Bot filtering

Use `jaybizzle/crawler-detect`. It is a maintained regex list and is the pragmatic choice here.

```bash
composer require jaybizzle/crawler-detect
```

Wrap it in `App\Analytics\BotDetector` with a single `isBot(?string $userAgent): bool` method so it can be swapped or faked in tests. Add a small supplementary deny-list for anything the library misses that shows up in this app's traffic (headless Chrome with default UA, uptime monitors, the app's own health checker).

### 6.6 Writing

`App\Analytics\Jobs\RecordPageview implements ShouldQueue`, constructed from an immutable `PageviewData` DTO holding only the fields in `analytics_events`. Body is a single `AnalyticsEvent::create()`.

Dispatch per `dispatch_mode`:

```php
$pending = RecordPageview::dispatch($data);

if (config('analytics.dispatch_mode') === 'after_response') {
    $pending->afterResponse();
} else {
    $pending->onQueue(config('analytics.queue_name'));
}
```

Wrap the whole middleware `terminate()` body in a try/catch that reports to the exception handler and swallows. Analytics must never be able to break a page render.

### 6.7 Scale escape hatch (build the interface, not the implementation)

Put writes behind `App\Analytics\Contracts\EventWriter` with a `DatabaseEventWriter`. If per-request inserts become a bottleneck, a `BufferedEventWriter` can push onto a Redis list and flush in batches from a scheduled command. Do not build the buffered writer now; just make the seam exist.

---

## 7. Country resolution

`App\Analytics\Geo\CountryResolver`, with three drivers selected by config.

### 7.1 `header` driver (preferred)

Read `config('analytics.geo.header')` off the request. Validate against `/^[A-Z]{2}$/` and reject the placeholders `XX` and `T1`. Zero cost, and the IP is never used for geo at all.

Before choosing this driver, run `analytics:doctor` against a deployed URL and check the header list for `CF-IPCountry`, `CloudFront-Viewer-Country`, `X-Vercel-IP-Country`, or similar. Do not assume Laravel Cloud provides one — verify, and fall back to `maxmind` if it does not.

### 7.2 `maxmind` driver

```bash
composer require geoip2/geoip2
```

Uses `GeoIp2\Database\Reader` against a local `GeoLite2-Country.mmdb`. Country database only, never City.

Operational notes for the implementer:

- The MaxMind license does not permit redistributing the database. **Do not commit the `.mmdb` to the repository.** Add `storage/app/geoip/` to `.gitignore`.
- Provide `php artisan analytics:geoip-update` that downloads the current GeoLite2-Country tarball using `MAXMIND_ACCOUNT_ID` and `MAXMIND_LICENSE_KEY`, extracts the `.mmdb`, and writes it atomically (temp file, then rename). Schedule it weekly.
- Laravel Cloud has an ephemeral filesystem, so also run this command as a deploy step, or store the file on S3 and pull it on boot.
- Resolve the reader lazily as a singleton. Catch `AddressNotFoundException` and `InvalidDatabaseException` and return null.

### 7.3 `none` driver

Returns null. The dashboard hides the country panel when every value is null.

---

## 8. Aggregation

### 8.1 Rollup command

`php artisan analytics:rollup {--date=} {--days=2}`

Recomputes `analytics_daily_totals` and `analytics_daily_dimensions` for the given days from `analytics_events`. Idempotent: delete-then-insert inside a transaction, per date. Default `--days=2` so today and yesterday are both refreshed, which handles late-arriving rows and timezone edges.

Totals:

```sql
SELECT COUNT(*) AS pageviews, COUNT(DISTINCT visitor_hash) AS visitors
FROM analytics_events WHERE date = ?
```

Dimensions, one query per dimension:

```sql
SELECT path AS value, COUNT(*) AS pageviews, COUNT(DISTINCT visitor_hash) AS visitors
FROM analytics_events WHERE date = ? GROUP BY path
```

Repeat for `country`, `referrer_host`, `utm_source`, skipping null values.

### 8.2 Schedule

In `routes/console.php`:

```php
Schedule::command('analytics:rollup --days=2')->hourly();
Schedule::command('analytics:prune')->dailyAt('03:15');
Schedule::command('analytics:geoip-update')->weeklyOn(1, '04:00');   // maxmind driver only
```

`analytics:prune` deletes `analytics_events` older than `retention.raw_days` and `analytics_salts` older than `retention.salt_hours`, chunked with `->limit(5000)` in a loop so it does not lock the table.

Confirm the Laravel Cloud scheduler is enabled for the app. Without it, rollups never run and the dashboard reads empty tables.

### 8.3 The multi-day uniques problem — read this before building queries

Because the salt rotates daily, the same person produces a different `visitor_hash` each day. There is no correct way to compute distinct visitors across a range. This is intentional; it is what makes the data anonymous.

Therefore:

- Every unique-visitor figure over a range is **the sum of daily uniques**, which double-counts returning visitors.
- The dashboard must label it accordingly and must never present it as a deduplicated count.
- Do not attempt to work around this by extending salt lifetime, by hashing without a salt, or by adding a stable identifier. Those changes break invariant 3 and reintroduce consent requirements.

Use the label **"Daily visitors (summed)"** with a tooltip: *"Someone who visits on three days counts three times. Visitor counts are only deduplicated within a single day, by design — we don't keep anything that would let us recognise a returning visitor."*

### 8.4 Query layer

`App\Analytics\AnalyticsRepository`, reading rollups for anything older than the current hour and raw events for today, so the dashboard is current between hourly rollups. Methods:

```php
totals(CarbonPeriod $period): Totals            // pageviews, summed visitors, views per visitor
timeseries(CarbonPeriod $period): Collection    // date => [pageviews, visitors]
topPages(CarbonPeriod $period, int $limit = 20)
countries(CarbonPeriod $period, int $limit = 30)
referrers(CarbonPeriod $period, int $limit = 20)
utmSources(CarbonPeriod $period, int $limit = 20)
liveVisitors(): int                              // distinct hashes, created_at >= now()->subMinutes(30)
```

Cache each method for 5 minutes keyed on method plus period, except `liveVisitors` (30 seconds).

---

## 9. Dashboard

### 9.1 Route and access

```php
Route::middleware(config('analytics.dashboard.middleware'))
    ->get('/analytics', AnalyticsDashboard::class)
    ->name('analytics.dashboard');
```

Register a `view-analytics` gate in `AppServiceProvider`. Default implementation should be conservative and obvious to change — check an `is_admin` column if one exists, otherwise an `ANALYTICS_ADMIN_EMAILS` comma-separated allow-list. Authorize in the component's `mount()`.

The dashboard path itself is in the `except` list, so viewing analytics never generates analytics.

### 9.2 Stack

Livewire 3 with a Blade view, since it gives filter state and polling without a build step. **If the host app uses Inertia**, build the same component as a controller returning an Inertia page with the same props instead; the repository layer is unchanged either way. Detect which by inspecting `composer.json` before starting.

Chart: Chart.js loaded through the app's existing Vite bundle. If the app has no Vite pipeline, render the timeseries as an inline SVG line chart from server-side data rather than adding a CDN script tag.

### 9.3 Layout

Single page, top to bottom:

1. **Header row.** Title, live-visitor pill ("7 visitors in the last 30 minutes", `wire:poll.30s`), and a date-range selector: 7 days, 30 days, 90 days, this month, custom. Range persists in the query string so views are linkable.
2. **Stat row.** Three cards: Pageviews, Daily visitors (summed), Views per visitor. Each shows the current value, and the change against the immediately preceding equal-length period as a signed percentage.
3. **Timeseries chart.** Pageviews and visitors as two lines over the range. Daily buckets, or hourly when the range is a single day.
4. **Breakdown grid.** Four panels: Pages, Countries, Referrers, Campaigns. Each is a ranked list with the label, pageviews, visitors, and a background bar scaled to the top row's value. Ten rows visible with a "Show all" expander. Countries render as full names via a static ISO map, not flag emoji (the user preference is no emoji, and flags are a poor accessibility choice regardless).

### 9.4 Design direction

This is an internal instrument panel inside an existing product. Adopt the host app's existing design tokens, typography, and container chrome first — a page that looks foreign to the rest of the admin area is a worse outcome than a plain one.

Within that, the specifics:

- **Density over decoration.** The point of the page is comparison. Tight vertical rhythm in the ranked lists, generous space only between the four panel groups.
- **Numerals are the subject.** Set all figures in a tabular-lining variant so columns align across rows; use `font-variant-numeric: tabular-nums`. Metric values get one clear step up in size and weight from their labels — do not achieve emphasis with color.
- **One accent, used only for data.** Pick a single hue from the host app's palette for chart lines and the ranked-list background bars. Interface chrome stays neutral. If everything can be accented, nothing reads as signal.
- **Comparison deltas are the only place color encodes meaning.** Positive and negative changes use two hues, and each is paired with an arrow glyph or a plus/minus sign so the meaning survives color blindness and grayscale printing.
- **Empty states are instructions.** A panel with no data says what would put data in it ("No referrers yet. Links from other sites will appear here."), not "No data available." A dashboard viewed before the first rollup should say so and name the command.
- **Quality floor:** responsive to a single column under 768px, visible keyboard focus on every control, `prefers-reduced-motion` respected in chart animation, and the ranked lists readable by screen reader as tables with proper headers.

---

## 10. Tests

Pest, feature tests against `RefreshDatabase`.

Recording:

1. A GET HTML request writes exactly one event row.
2. POST, JSON, redirects, and 404s write nothing.
3. A request with a known bot user agent writes nothing.
4. An excluded path writes nothing.
5. Two requests from the same IP and UA on the same day produce identical hashes; the same pair with the salt row replaced produces a different hash.
6. `/posts/hello-world` is recorded as `/posts/{slug}` when the route is defined that way.
7. Query strings are stripped; `utm_source` is captured.
8. A same-host referrer is stored as null; an external one is stored as its bare host.
9. **No event row, job payload, or log line contains the client IP.** Assert directly against the serialized job payload.
10. The response carries no `Set-Cookie` header for a guest on a tracked page.

Aggregation:

11. Rollup produces correct totals and per-dimension rows for a seeded day.
12. Rollup run twice for the same date does not duplicate rows.
13. Prune deletes events past retention and salts past 48 hours, and leaves rollups untouched.

Dashboard:

14. A guest is redirected; an authenticated non-admin gets 403; an admin gets 200.
15. The dashboard renders correct figures from seeded rollups.
16. Visiting the dashboard creates no analytics events.

---

## 11. Build order

1. Config, migrations, models, `analytics:doctor`.
2. Deploy and run `analytics:doctor` against the live URL. Confirm the client IP is real and check for a country header. **Do not proceed until this passes** — the two most expensive failures in this system (untrusted proxies, missing geo header) are both invisible until data is already wrong.
3. Salt repository, bot detector, path normalizer, country resolver.
4. Middleware and job. Tests 1 through 10.
5. Rollup, prune, schedule. Tests 11 through 13.
6. Repository and dashboard. Tests 14 through 16.

---

## 12. Privacy policy copy

Add to the existing policy:

> **Analytics.** We count visits to understand which pages are useful. We do this ourselves, on our own servers, without cookies or any other storage on your device, and without any third-party analytics service.
>
> When you load a page we record the page address, the country your request came from, and the site that linked you, if any. To estimate how many separate people visited on a given day, we combine your IP address, browser identifier, and a secret value that we replace every day into a one-way fingerprint. Your IP address is never written to disk. The daily secret is destroyed after 48 hours, at which point the fingerprints cannot be linked to each other or to you, even by us.
>
> This means we cannot recognise you when you return, cannot follow you to other sites, and hold nothing that identifies you.

If the site serves the EU, note in internal documentation that the design follows the CNIL exemption criteria for audience measurement, and have counsel confirm before removing any existing consent banner. This spec is an engineering document, not legal advice.
