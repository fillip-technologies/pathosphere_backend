<?php

namespace App\Modules\Lab\Domain;

use App\Modules\Lab\Enums\ReportStatus;
use App\Modules\Lab\Enums\WorklistStatus;

/**
 * Where an unreleased report stands (spec §5.5 steps 3–5): a draft until
 * every test on it is verified, then waiting for signatures until every
 * department on it is signed by an eligible signatory.
 */
final class ReportReadiness
{
    /**
     * @param  array<string, list<WorklistStatus>>  $testStatusesByDepartment  live tests only (not withdrawn)
     * @param  list<string>  $signedDepartmentIds
     */
    public static function statusFor(array $testStatusesByDepartment, array $signedDepartmentIds): ReportStatus
    {
        if ($testStatusesByDepartment === []) {
            return ReportStatus::Draft;
        }

        foreach (array_keys($testStatusesByDepartment) as $departmentId) {
            if (! self::departmentIsVerified($testStatusesByDepartment, $departmentId)) {
                return ReportStatus::Draft;
            }
        }

        $unsigned = array_diff(array_keys($testStatusesByDepartment), $signedDepartmentIds);

        return $unsigned === [] ? ReportStatus::Signed : ReportStatus::PendingSignature;
    }

    /**
     * A department's section can be signed once all its tests are verified,
     * even while other departments are still working.
     *
     * @param  array<string, list<WorklistStatus>>  $testStatusesByDepartment
     */
    public static function departmentIsVerified(array $testStatusesByDepartment, string $departmentId): bool
    {
        $statuses = $testStatusesByDepartment[$departmentId] ?? [];

        return $statuses !== [] && array_filter($statuses, fn (WorklistStatus $status) => $status !== WorklistStatus::Verified) === [];
    }
}
