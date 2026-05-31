<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily activity counts for dashboard heatmaps (last N weeks).
 *
 * Aggregation runs in PHP after a `pluck('created_at')`, so we stay portable
 * across SQLite (dev), Postgres (prod / Supabase) and MySQL — none of the
 * driver-specific date functions or alias-in-GROUP-BY quirks bite us.
 *
 * Wrapped in a try/catch with `report()` so a failure here can never tumble
 * the page render. Returns an empty map on error.
 */
class ActivityFeed
{
    /**
     * Counts records per day (Y-m-d) for the last N weeks.
     *
     * @return array<string, int>
     */
    public static function dailyCounts(Builder $query, int $weeks = 13): array
    {
        try {
            $since = now()->subWeeks($weeks)->startOfDay();

            return $query
                ->where('created_at', '>=', $since)
                ->pluck('created_at')
                ->filter()
                ->countBy(fn (CarbonInterface $ts) => $ts->format('Y-m-d'))
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Merge two daily-count maps, summing collisions.
     *
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return array<string, int>
     */
    public static function merge(array $a, array $b): array
    {
        foreach ($b as $day => $count) {
            $a[$day] = ($a[$day] ?? 0) + $count;
        }

        return $a;
    }
}
