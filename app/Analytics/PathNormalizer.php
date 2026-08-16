<?php

namespace App\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Normalizes everything App\Analytics\Models\AnalyticsEvent derives from
 * a request's URL: the matched route's own URI pattern (so dynamic
 * segments like {slug} collapse into one "page" instead of one row per
 * distinct value), UTM params, and the referring host. See
 * docs/specs/analytics.md section 6.4.
 */
class PathNormalizer
{
    public static function path(Request $request): string
    {
        $path = $request->route()?->uri();
        $path = $path ? '/'.ltrim($path, '/') : '/'.trim($request->path(), '/');
        $path = $path === '//' ? '/' : (rtrim($path, '/') ?: '/');

        return Str::limit($path, 255, '');
    }

    public static function routeName(Request $request): ?string
    {
        return $request->route()?->getName();
    }

    /**
     * @return array{utm_source: ?string, utm_medium: ?string, utm_campaign: ?string}
     */
    public static function utmParams(Request $request): array
    {
        return collect(['utm_source', 'utm_medium', 'utm_campaign'])
            ->mapWithKeys(function (string $key) use ($request) {
                $value = $request->query($key);

                return [$key => is_string($value) ? Str::lower(Str::limit($value, 64, '')) : null];
            })
            ->all();
    }

    /**
     * Null for internal navigation (the referrer is this app's own
     * host) -- that isn't a referral, and isn't worth a row.
     */
    public static function referrerHost(Request $request): ?string
    {
        $referrer = $request->headers->get('referer');

        if (! is_string($referrer) || $referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = Str::lower($host);
        $host = Str::startsWith($host, 'www.') ? substr($host, 4) : $host;

        $appHost = Str::lower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host === $appHost ? null : Str::limit($host, 128, '');
    }
}
