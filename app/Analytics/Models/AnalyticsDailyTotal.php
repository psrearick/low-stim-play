<?php

namespace App\Analytics\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per day, kept indefinitely (unlike AnalyticsEvent). Recomputed
 * from AnalyticsEvent by `analytics:rollup` -- delete-then-insert per
 * date, so this table is always safely derivable from raw events while
 * they still exist.
 *
 * @property string $date plain 'Y-m-d' -- see AnalyticsEvent's own $date
 *                        docblock for why this is deliberately not cast to Carbon
 * @property int $pageviews
 * @property int $visitors
 */
#[Fillable(['date', 'pageviews', 'visitors'])]
class AnalyticsDailyTotal extends Model
{
    protected $primaryKey = 'date';

    protected $keyType = 'string';

    public $incrementing = false;
}
