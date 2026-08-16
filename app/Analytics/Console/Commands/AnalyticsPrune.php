<?php

namespace App\Analytics\Console\Commands;

use App\Analytics\Models\AnalyticsEvent;
use App\Analytics\Models\AnalyticsSalt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deletes analytics_events past config('analytics.retention.raw_days')
 * and analytics_salts past config('analytics.retention.salt_hours').
 * Salts are the reason hashes are anonymous -- see docs/specs/
 * analytics.md's privacy invariant 4 -- so this command, not just
 * analytics_events pruning, is what actually makes the "unlinkable
 * across days" guarantee true rather than aspirational.
 */
#[Signature('analytics:prune')]
#[Description('Delete analytics_events past their retention window and analytics_salts past 48 hours')]
class AnalyticsPrune extends Command
{
    /**
     * Chunked rather than one bulk delete so a large backlog doesn't
     * hold a long-running lock on either table.
     */
    private const int CHUNK_SIZE = 5000;

    public function handle(): int
    {
        $eventsDeleted = $this->deleteInChunks(
            AnalyticsEvent::where('date', '<', now()->subDays((int) config('analytics.retention.raw_days'))->toDateString())
        );

        $saltsDeleted = $this->deleteInChunks(
            AnalyticsSalt::where('created_at', '<', now()->subHours((int) config('analytics.retention.salt_hours')))
        );

        $this->info("Deleted {$eventsDeleted} analytics event(s) and {$saltsDeleted} salt(s).");

        return self::SUCCESS;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function deleteInChunks(Builder $query): int
    {
        $total = 0;

        do {
            $deleted = (clone $query)->limit(self::CHUNK_SIZE)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }
}
