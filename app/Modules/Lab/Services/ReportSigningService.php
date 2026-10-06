<?php

namespace App\Modules\Lab\Services;

use App\Modules\Auth\Services\SigningConfirmation;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Catalogue\Services\DepartmentFacts;
use App\Modules\Catalogue\Services\TestDirectory;
use App\Modules\Lab\Domain\ReportReadiness;
use App\Modules\Lab\Domain\SignatoryCredential;
use App\Modules\Lab\Domain\SignatoryEligibility;
use App\Modules\Lab\Enums\ReportStatus;
use App\Modules\Lab\Enums\WorklistStatus;
use App\Modules\Lab\Errors\LabError;
use App\Modules\Lab\Models\Report;
use App\Modules\Lab\Models\ReportSignature;
use App\Modules\Shared\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A signatory signs the departments of a report they are qualified for
 * (spec §5.5 step 4, §10 legal rules). Signing re-asks the authenticator
 * code. A department can be signed as soon as its own results are verified,
 * while other departments are still working.
 */
final class ReportSigningService
{
    public function __construct(
        private readonly SigningConfirmation $confirmation,
        private readonly SignatoryService $signatories,
        private readonly TestDirectory $tests,
        private readonly ReportAssembly $reports,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  list<string>|null  $departmentIds  null: every department the signer may sign now
     */
    public function sign(StaffContext $staff, Report $report, ?array $departmentIds, string $code, ?string $signerIp): Report
    {
        if (! in_array($report->status, [ReportStatus::Draft, ReportStatus::PendingSignature], true)) {
            throw $report->status === ReportStatus::Signed ? LabError::nothingToSign() : LabError::reportNotSignable();
        }

        $this->confirmation->confirm($staff, $code);
        $credentials = $this->signatories->credentialsOf($report->organization_id, $staff->user()->id);

        return DB::transaction(function () use ($staff, $report, $departmentIds, $credentials, $signerIp): Report {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);
            $statuses = $this->reports->testStatusesByDepartment($report);
            $departments = $this->tests->departments(array_keys($statuses));
            $signed = $report->signatures()->pluck('department_id')->all();
            $today = CarbonImmutable::today();

            $toSign = $departmentIds === null
                ? $this->signableNow($staff, $report, $statuses, $departments, $signed, $credentials, $today)
                : $this->requested($staff, $report, $departmentIds, $statuses, $departments, $signed, $credentials, $today);

            if ($toSign === []) {
                throw LabError::nothingToSign();
            }

            foreach ($toSign as $departmentId => $credential) {
                $signature = new ReportSignature(['signed_at' => CarbonImmutable::now(), 'signer_ip' => $signerIp]);
                $signature->forceFill([
                    'report_id' => $report->id,
                    'signatory_id' => $credential->signatoryId,
                    'department_id' => $departmentId,
                ])->save();
            }

            $this->auditLogger->record('report.sign', $report, [], ['department_ids' => array_keys($toSign)]);
            $this->reports->refresh($report);

            return $report;
        });
    }

    /**
     * @param  array<string, list<WorklistStatus>>  $statuses
     * @param  array<string, DepartmentFacts>  $departments
     * @param  list<string>  $signed
     * @param  list<SignatoryCredential>  $credentials
     * @return array<string, SignatoryCredential> department ID => the credential signing it
     */
    private function signableNow(StaffContext $staff, Report $report, array $statuses, array $departments, array $signed, array $credentials, CarbonImmutable $today): array
    {
        $toSign = [];

        foreach ($departments as $department) {
            if (in_array($department->id, $signed, true) || ! ReportReadiness::departmentIsVerified($statuses, $department->id)) {
                continue;
            }

            $credential = $this->credential($staff, $report, $department, $credentials, $today);

            if ($credential !== null) {
                $toSign[$department->id] = $credential;
            }
        }

        return $toSign;
    }

    /**
     * Each requested department must be on the report, verified and within
     * the signer's authority; ones already signed are skipped.
     *
     * @param  list<string>  $departmentIds
     * @param  array<string, list<WorklistStatus>>  $statuses
     * @param  array<string, DepartmentFacts>  $departments
     * @param  list<string>  $signed
     * @param  list<SignatoryCredential>  $credentials
     * @return array<string, SignatoryCredential>
     */
    private function requested(StaffContext $staff, Report $report, array $departmentIds, array $statuses, array $departments, array $signed, array $credentials, CarbonImmutable $today): array
    {
        $toSign = [];

        foreach (array_unique($departmentIds) as $departmentId) {
            $department = $departments[$departmentId] ?? throw LabError::departmentNotOnReport();

            if (! ReportReadiness::departmentIsVerified($statuses, $departmentId)) {
                throw LabError::departmentNotVerified($department->name);
            }

            $credential = $this->credential($staff, $report, $department, $credentials, $today) ?? throw LabError::signatoryNotAuthorised();

            if (! in_array($departmentId, $signed, true)) {
                $toSign[$departmentId] = $credential;
            }
        }

        return $toSign;
    }

    /** @param  list<SignatoryCredential>  $credentials */
    private function credential(StaffContext $staff, Report $report, DepartmentFacts $department, array $credentials, CarbonImmutable $today): ?SignatoryCredential
    {
        return SignatoryEligibility::credentialFor(
            $credentials,
            $staff->user()->id,
            $report->processing_branch_id,
            $department->id,
            $department->signingDiscipline,
            $today,
        );
    }
}
