<?php

namespace App\Analytics;

use Jaybizzle\CrawlerDetect\CrawlerDetect;

/**
 * Wraps jaybizzle/crawler-detect behind a single method so it can be
 * swapped or faked in tests, per docs/specs/analytics.md section 6.5.
 */
class BotDetector
{
    /**
     * Anything the library misses that shows up in this app's own
     * traffic -- starts small and is meant to grow from real observed
     * user agents, not be exhaustive up front.
     *
     * @var list<string>
     */
    private const array SUPPLEMENTARY_PATTERNS = [
        'HeadlessChrome',
        'UptimeRobot',
        'Pingdom',
        'StatusCake',
        'Site24x7',
    ];

    public function __construct(private readonly CrawlerDetect $crawlerDetect = new CrawlerDetect) {}

    public function isBot(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return true;
        }

        if ($this->crawlerDetect->isCrawler($userAgent)) {
            return true;
        }

        foreach (self::SUPPLEMENTARY_PATTERNS as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
