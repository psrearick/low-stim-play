<?php

namespace App\Analytics\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per (date, dimension, value) breakdown -- 'path' | 'country' |
 * 'referrer' | 'utm_source' -- recomputed from AnalyticsEvent by
 * `analytics:rollup`. A single table for all four breakdowns keeps the
 * query layer small; see App\Analytics\AnalyticsRepository.
 *
 * @property string $date plain 'Y-m-d' -- see AnalyticsEvent's own $date
 *                        docblock for why this is deliberately not cast to Carbon
 * @property string $dimension
 * @property string $value
 * @property int $pageviews
 * @property int $visitors
 */
#[Fillable(['date', 'dimension', 'value', 'pageviews', 'visitors'])]
class AnalyticsDailyDimension extends Model
{
    //
}
