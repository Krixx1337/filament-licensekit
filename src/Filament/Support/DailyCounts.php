<?php

namespace LicenseKit\Filament\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Per-day counts for dashboard charts, using UTC calendar days. */
final class DailyCounts
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  'created_at'|'redeemed_at'  $column
     * @return array<string, int> one entry per day, oldest first, missing days as 0
     */
    public static function last(int $days, Builder $query, string $column): array
    {
        $start = CarbonImmutable::now('UTC')->startOfDay()->subDays($days - 1);
        $totals = $query->where($column, '>=', $start->format('Y-m-d H:i:s'))
            ->selectRaw("DATE({$column}) as day, COUNT(*) as total")->groupBy('day')->toBase()->pluck('total', 'day');
        $series = [];
        for ($day = $start; $day->lessThanOrEqualTo(CarbonImmutable::now('UTC')); $day = $day->addDay()) {
            $series[$day->format('Y-m-d')] = (int) ($totals[$day->format('Y-m-d')] ?? 0);
        }

        return $series;
    }
}
