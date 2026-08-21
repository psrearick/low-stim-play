<?php

namespace App\Analytics;

/**
 * Immutable snapshot of a single pageview -- holds only the fields
 * App\Analytics\Models\AnalyticsEvent stores. Deliberately excludes the
 * IP address and user agent: App\Analytics\Http\Middleware\RecordPageview
 * hashes those into visitorHash before this DTO is ever constructed, so
 * there is nothing to leak into a queued job's serialized payload. See
 * docs/specs/analytics.md's privacy invariant 2.
 */
final readonly class PageviewData
{
    public function __construct(
        public string $date,
        public int $hour,
        public string $visitorHash,
        public string $path,
        public ?string $routeName,
        public ?string $country,
        public ?string $region,
        public ?string $referrerHost,
        public ?string $utmSource,
        public ?string $utmMedium,
        public ?string $utmCampaign,
        public string $createdAt,
    ) {}

    /**
     * @return array{date: string, hour: int, visitor_hash: string, path: string, route_name: ?string, country: ?string, region: ?string, referrer_host: ?string, utm_source: ?string, utm_medium: ?string, utm_campaign: ?string, created_at: string}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'hour' => $this->hour,
            'visitor_hash' => $this->visitorHash,
            'path' => $this->path,
            'route_name' => $this->routeName,
            'country' => $this->country,
            'region' => $this->region,
            'referrer_host' => $this->referrerHost,
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
            'created_at' => $this->createdAt,
        ];
    }
}
