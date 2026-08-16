<?php

namespace App\Analytics\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The daily secret that makes visitor hashes unlinkable across days --
 * see docs/specs/analytics.md's privacy invariants. Rows older than
 * config('analytics.retention.salt_hours') are hard-deleted by
 * `analytics:prune`; once that happens the hashes derived from them are
 * anonymous data with no key in existence.
 *
 * @property string $date
 * @property string $salt
 */
#[Fillable(['date', 'salt'])]
class AnalyticsSalt extends Model
{
    //
}
