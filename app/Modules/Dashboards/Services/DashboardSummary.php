<?php

namespace App\Modules\Dashboards\Services;

use App\Modules\Dashboards\Models\DailyBranchMetric;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sums the nightly metrics for a dashboard: totals for the period, a series
 * by day and a breakdown by branch. Reads only the summary table.
 */
final class DashboardSummary
{
    /**
     * @param  Builder<DailyBranchMetric>  $metrics  already narrowed to the dashboard's slice
     * @return array<string, mixed>
     */
    public function summarise(Builder $metrics, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $metrics
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('metric_date')
            ->get();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->totals($rows),
            'by_day' => $rows->groupBy(fn (DailyBranchMetric $row) => $row->metric_date->toDateString())
                ->map(fn (Collection $day, string $date) => ['date' => $date, ...$this->totals($day)])
                ->values()
                ->all(),
            'by_branch' => $rows->groupBy('branch_id')
                ->map(fn (Collection $branch, string $branchId) => ['branch_id' => $branchId, ...$this->totals($branch)])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, DailyBranchMetric>  $rows
     * @return array<string, int|Money>
     */
    private function totals(Collection $rows): array
    {
        $totals = [];

        foreach (DailyBranchMetric::COUNTERS as $counter) {
            $totals[$counter] = (int) $rows->sum($counter);
        }

        foreach (DailyBranchMetric::AMOUNTS as $amount) {
            $totals[$amount] = $rows->reduce(fn (Money $sum, DailyBranchMetric $row) => $sum->add($row->{$amount}), Money::zero());
        }

        return $totals;
    }
}
