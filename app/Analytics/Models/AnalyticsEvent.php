<?php

namespace App\Analytics\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A single recorded pageview. Insert-only and short-lived -- rows past
 * config('analytics.retention.raw_days') are hard-deleted by
 * `analytics:prune` once `analytics:rollup` has folded them into
 * AnalyticsDailyTotal/AnalyticsDailyDimension. Never holds a raw IP
 * address or user agent, only the derived visitor_hash -- see
 * docs/specs/analytics.md's privacy invariants.
 *
 * @property string $date plain 'Y-m-d' -- deliberately not cast to
 *                        Carbon; a 'date' cast serializes to a full datetime string on
 *                        write, which a real DATE column (MariaDB/Postgres) silently
 *                        truncates but SQLite's dynamic typing does not, breaking every
 *                        plain-string `where('date', $date)` comparison this app's rollup/
 *                        prune/repository queries rely on
 * @property int $hour
 * @property string $visitor_hash
 * @property string $path
 * @property ?string $route_name
 * @property ?string $country
 * @property ?string $referrer_host
 * @property ?string $utm_source
 * @property ?string $utm_medium
 * @property ?string $utm_campaign
 */
#[Fillable([
    'date', 'hour', 'visitor_hash', 'path', 'route_name', 'country',
    'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign', 'created_at',
])]
class AnalyticsEvent extends Model
{
    /**
     * No updated_at column -- rows are never modified after insert.
     */
    const ?string UPDATED_AT = null;
}
