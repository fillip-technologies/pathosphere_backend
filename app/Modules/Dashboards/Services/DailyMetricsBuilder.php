<?php

namespace App\Modules\Dashboards\Services;

use App\Modules\Booking\Services\BookingDailyFigures;
use App\Modules\Booking\Services\BranchBookingDay;
use App\Modules\Dashboards\Models\DailyBranchMetric;
use App\Modules\Lab\Services\LabDailyFigures;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Samples\Services\SampleDailyFigures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds one business day of per-branch metrics from each module's figures.
 * Rebuilding a day replaces it, so a late correction is picked up by running
 * the day again. Branches with no activity get no row.
 */
final class DailyMetricsBuilder
{
    public function __construct(
        private readonly NetworkDirectory $network,
        private readonly BookingDailyFigures $booking,
        private readonly SampleDailyFigures $samples,
        private readonly LabDailyFigures $lab,
    ) {}

    public function build(string $organizationId, CarbonImmutable $date): int
    {
        $booking = $this->booking->forDay($organizationId, $date);
        $rejected = $this->samples->rejectedByCollectingBranch($organizationId, $date);
        $released = $this->lab->reportsReleased($organizationId, $date);
        $breaches = $this->lab->turnaroundBreaches($organizationId, $date);

        return DB::transaction(function () use ($organizationId, $date, $booking, $rejected, $released, $breaches): int {
            DailyBranchMetric::query()->where('metric_date', $date->toDateString())->delete();
            $written = 0;

            foreach ($this->network->branchPlacements($organizationId) as $branch) {
                $day = $booking[$branch->branchId] ?? new BranchBookingDay;
                $row = [
                    'orders_booked' => $day->ordersBooked,
                    'orders_cancelled' => $day->ordersCancelled,
                    'tests_ordered' => $day->testsOrdered,
                    'gross_billing' => $day->grossBilling,
                    'collected' => $day->collected,
                    'samples_rejected' => $rejected[$branch->branchId] ?? 0,
                    'reports_released' => $released[$branch->branchId] ?? 0,
                    'tat_breaches' => $breaches[$branch->branchId] ?? 0,
                ];

                if ($this->isEmpty($row)) {
                    continue;
                }

                $metric = new DailyBranchMetric($row);
                $metric->forceFill([
                    'organization_id' => $organizationId,
                    'metric_date' => $date->toDateString(),
                    'branch_id' => $branch->branchId,
                    'region_id' => $branch->regionId,
                    'franchise_id' => $branch->franchiseId,
                ])->save();
                $written++;
            }

            return $written;
        });
    }

    /** @param  array<string, mixed>  $row */
    private function isEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (is_int($value) ? $value !== 0 : ! $value->isZero()) {
                return false;
            }
        }

        return true;
    }
}
