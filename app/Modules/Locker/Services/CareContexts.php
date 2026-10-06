<?php

namespace App\Modules\Locker\Services;

use App\Modules\Booking\Services\AbhaIdentity;
use App\Modules\Lab\Services\ReleasedReports;
use App\Modules\Locker\Contracts\Abdm\AbhaPatient;
use App\Modules\Locker\Contracts\Abdm\CareContext;
use App\Modules\Locker\Domain\DiscoveryMatcher;
use App\Modules\Locker\Enums\CareContextLinkStatus;
use App\Modules\Locker\Models\AbdmCareContext;
use App\Modules\Shared\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Released reports as ABDM care contexts (spec §5.7 abdm_care_contexts):
 * one per report version, referenced by the report's ID and named the way
 * the patient sees it in their ABHA app.
 */
final class CareContexts
{
    private const TIMEZONE = 'Asia/Kolkata';

    public function __construct(
        private readonly ReleasedReports $releasedReports,
        private readonly ReleasedReportBridge $bridge,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * The care context of a released report, created pending if new. Null
     * when the report was never released or its lab has no HFR ID (it cannot
     * be a Health Information Provider without one).
     *
     * @param  string  $patientId  the patient the report belongs to today (after merges)
     */
    public function ensure(string $organizationId, string $reportId, string $patientId): ?AbdmCareContext
    {
        $existing = AbdmCareContext::query()->where('report_id', $reportId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $report = $this->releasedReports->forExchange($organizationId, $reportId);

        if ($report === null || $report->lab->hfrId === null) {
            return null;
        }

        // The data ABDM receives is read from the locker's copy, which keeps each version as released.
        $recordId = $this->bridge->copy($organizationId, $reportId);

        if ($recordId === null) {
            return null;
        }

        $careContext = new AbdmCareContext([
            'care_context_reference' => $report->reportId,
            'display_name' => self::displayName($report->orderDate, $report->lab->name, $report->version),
            'hi_type' => AbdmCareContext::HI_TYPE,
            'link_status' => CareContextLinkStatus::Pending,
        ]);
        $careContext->organization_id = $organizationId;
        $careContext->patient_id = $patientId;
        $careContext->report_id = $report->reportId;
        $careContext->medical_record_id = $recordId;
        $careContext->hip_branch_id = $report->lab->branchId;

        try {
            $careContext->save();
        } catch (UniqueConstraintViolationException) {
            // Created at the same moment by the release listener or the patient's own linking.
            return AbdmCareContext::query()->where('report_id', $reportId)->first();
        }

        $this->auditLogger->recordCreated('abdm_care_context.created', $careContext);

        return $careContext;
    }

    /** e.g. "Lab report, 05 Oct 2026, Patna Clinical Lab". */
    public static function displayName(CarbonImmutable $orderDate, string $labName, int $version): string
    {
        $name = sprintf('Lab report, %s, %s', $orderDate->setTimezone(self::TIMEZONE)->format('d M Y'), $labName);

        return mb_strimwidth($version > 1 ? "{$name} (corrected)" : $name, 0, 200, '…');
    }

    public static function asAbdmCareContext(AbdmCareContext $careContext): CareContext
    {
        return new CareContext($careContext->care_context_reference, $careContext->display_name);
    }

    public static function abhaPatient(AbhaIdentity $identity): AbhaPatient
    {
        return new AbhaPatient(
            $identity->abhaNumber,
            $identity->abhaAddress,
            $identity->name,
            DiscoveryMatcher::abdmGender($identity->gender),
            $identity->yearOfBirth,
        );
    }
}
