<?php

namespace App\Analytics\Http\Middleware;

use App\Analytics\BotDetector;
use App\Analytics\Geo\CountryResolver;
use App\Analytics\Jobs\RecordPageview as RecordPageviewJob;
use App\Analytics\PageviewData;
use App\Analytics\PathNormalizer;
use App\Analytics\SaltRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Appended to the 'web' group in bootstrap/app.php. All the actual work
 * happens in terminate(), which runs after the response has already been
 * sent, so tracking never adds to response latency and a failure here
 * can never break a page render (see the try/catch below).
 */
class RecordPageview
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if ($this->shouldSkip($request, $response)) {
                return;
            }

            $now = now();

            $visitorHash = hash('sha256', implode('|', [
                app(SaltRepository::class)->forToday(),
                (string) $request->ip(),
                (string) $request->userAgent(),
                $request->getHost(),
            ]));

            $utm = PathNormalizer::utmParams($request);

            $data = new PageviewData(
                date: $now->toDateString(),
                hour: (int) $now->format('G'),
                visitorHash: $visitorHash,
                path: PathNormalizer::path($request),
                routeName: PathNormalizer::routeName($request),
                country: app(CountryResolver::class)->resolve($request->ip()),
                referrerHost: PathNormalizer::referrerHost($request),
                utmSource: $utm['utm_source'],
                utmMedium: $utm['utm_medium'],
                utmCampaign: $utm['utm_campaign'],
                createdAt: $now->toDateTimeString(),
            );

            $pending = RecordPageviewJob::dispatch($data);

            if (config('analytics.dispatch_mode') === 'after_response') {
                $pending->afterResponse();
            } else {
                $pending->onQueue(config('analytics.queue_name'));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function shouldSkip(Request $request, Response $response): bool
    {
        if (! config('analytics.enabled')) {
            return true;
        }

        if (! $request->isMethod('GET')) {
            return true;
        }

        // 2xx only, not "2xx or 3xx" as docs/specs/analytics.md section 6.2
        // literally lists: Symfony's RedirectResponse defaults its own
        // Content-Type to text/html (a tiny "redirecting..." body), so a
        // 3xx would otherwise pass the content-type check below too and
        // get recorded as a phantom pageview for a page the visitor never
        // actually saw -- see docs/DEVLOG.md's Analytics section.
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            return true;
        }

        if (! Str::contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return true;
        }

        if ($request->headers->has('X-Requested-With')
            || $request->headers->has('X-Livewire')
            || $request->headers->has('X-Inertia')
            || $request->headers->get('Purpose') === 'prefetch'
            || $request->headers->get('Sec-Purpose') === 'prefetch'
        ) {
            return true;
        }

        if (Str::is(config('analytics.except'), $request->path())) {
            return true;
        }

        if (config('analytics.respect_dnt') && $request->headers->get('DNT') === '1') {
            return true;
        }

        if (app(BotDetector::class)->isBot($request->userAgent())) {
            return true;
        }

        return false;
    }
}
