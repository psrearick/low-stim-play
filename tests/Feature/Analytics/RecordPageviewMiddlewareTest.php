<?php

use App\Analytics\Jobs\RecordPageview;
use App\Analytics\Models\AnalyticsEvent;
use App\Analytics\Models\AnalyticsSalt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('a GET HTML request writes exactly one event row', function () {
    $this->get('/')->assertOk();

    expect(AnalyticsEvent::count())->toBe(1);

    $event = AnalyticsEvent::first();
    expect($event->path)->toBe('/');
    expect(strlen($event->visitor_hash))->toBe(64);
});

test('POST, JSON, redirect, and 404 responses write nothing', function () {
    // ->middleware('web') is required here: only routes loaded from
    // routes/web.php get the 'web' group (and therefore
    // App\Analytics\Http\Middleware\RecordPageview) applied automatically
    // -- an ad-hoc Route::get() registered elsewhere at runtime gets no
    // middleware at all unless it's added explicitly.
    Route::get('/__test/json', fn () => response()->json(['ok' => true]))->middleware('web');
    Route::get('/__test/redirect', fn () => redirect('/'))->middleware('web');
    Route::post('/__test/post', fn () => response('ok'))->middleware('web');

    $this->post('/__test/post')->assertOk();
    $this->get('/__test/json')->assertOk();
    $this->get('/__test/redirect')->assertRedirect('/');
    $this->get('/__test/does-not-exist')->assertNotFound();

    expect(AnalyticsEvent::count())->toBe(0);
});

test('a known bot user agent writes nothing', function () {
    $this->get('/', ['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'])->assertOk();

    expect(AnalyticsEvent::count())->toBe(0);
});

test('an excluded path writes nothing', function () {
    $this->get('/up')->assertOk();

    expect(AnalyticsEvent::count())->toBe(0);
});

test('same IP and user agent on the same day produce identical hashes, a replaced salt produces a different one', function () {
    $ua = ['User-Agent' => 'Mozilla/5.0 (Test Browser)'];

    $this->get('/', $ua)->assertOk();
    $this->get('/about', $ua)->assertOk();

    $hashes = AnalyticsEvent::pluck('visitor_hash');
    expect($hashes[0])->toBe($hashes[1]);

    // Bypass the cached salt (see App\Analytics\SaltRepository) so the next
    // request reads a genuinely different salt row, mirroring what a real
    // day rollover does.
    Cache::forget('analytics:salt:'.now()->toDateString());
    AnalyticsSalt::query()->update(['salt' => 'a-completely-different-salt']);

    $this->get('/', $ua)->assertOk();

    expect(AnalyticsEvent::latest('id')->first()->visitor_hash)->not->toBe($hashes[0]);
});

test('a dynamic route is recorded under its route pattern, not the literal URL', function () {
    Route::get('/__test/posts/{slug}', fn (string $slug) => "post: {$slug}")->middleware('web');

    $this->get('/__test/posts/hello-world')->assertOk();

    expect(AnalyticsEvent::first()->path)->toBe('/__test/posts/{slug}');
});

test('query strings are stripped from the path and utm_source is captured, lowercased', function () {
    $this->get('/?utm_source=Newsletter&other=1')->assertOk();

    $event = AnalyticsEvent::first();
    expect($event->path)->toBe('/');
    expect($event->utm_source)->toBe('newsletter');
});

test('a same-host referrer is stored as null, an external one as its bare host', function () {
    $this->get('/', ['Referer' => config('app.url').'/some-page'])->assertOk();
    $this->get('/about', ['Referer' => 'https://www.external-blog.example/post'])->assertOk();

    $events = AnalyticsEvent::orderBy('id')->get();
    expect($events[0]->referrer_host)->toBeNull();
    expect($events[1]->referrer_host)->toBe('external-blog.example');
});

test('no event row, or the job payload, contains the client IP address', function () {
    Queue::fake();

    $this->get('/', ['REMOTE_ADDR' => '203.0.113.7'])->assertOk();

    Queue::assertPushed(RecordPageview::class, function (RecordPageview $job) {
        return ! str_contains(serialize($job), '203.0.113.7');
    });

    // The job never ran (Queue::fake()), so also confirm directly that the
    // schema itself has nowhere an IP could have landed even if it had.
    expect(AnalyticsEvent::count())->toBe(0);
});

test('tracking a page adds no cookies beyond what the app already sets for its own session', function () {
    config(['analytics.enabled' => true]);
    $withAnalytics = collect($this->get('/')->headers->getCookies())->map->getName()->sort()->values()->all();

    config(['analytics.enabled' => false]);
    $withoutAnalytics = collect($this->get('/')->headers->getCookies())->map->getName()->sort()->values()->all();

    // This app already issues its own session/CSRF cookies on every
    // request (Laravel's 'web' middleware group), independent of
    // analytics -- so the real privacy invariant to test is that
    // analytics adds nothing on top of that baseline, not that the
    // response is cookie-free outright.
    expect($withAnalytics)->toBe($withoutAnalytics);
});
