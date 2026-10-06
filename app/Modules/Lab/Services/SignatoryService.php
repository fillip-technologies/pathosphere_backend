<?php

namespace App\Modules\Lab\Services;

use App\Modules\Auth\Permissions\Permission;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Auth\Services\StaffDirectory;
use App\Modules\Catalogue\Enums\SigningDiscipline;
use App\Modules\Catalogue\Services\TestDirectory;
use App\Modules\Lab\Domain\SignatoryCredential;
use App\Modules\Lab\Domain\SignatoryEligibility;
use App\Modules\Lab\Errors\LabError;
use App\Modules\Lab\Models\Signatory;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Files\PrivateFileStore;
use App\Modules\Shared\Files\PrivatePaths;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HQ's register of who may sign which department at which lab (spec §7.2).
 * Signature images live in private storage and are only ever embedded in
 * report PDFs.
 */
final class SignatoryService
{
    public function __construct(
        private readonly CurrentScope $currentScope,
        private readonly NetworkDirectory $network,
        private readonly TestDirectory $tests,
        private readonly StaffDirectory $staffDirectory,
        private readonly PrivateFileStore $files,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param  array<string, mixed>  $attributes  validated request fields */
    public function create(StaffContext $staff, array $attributes, UploadedFile $signatureImage): Signatory
    {
        $organizationId = $staff->user()->organization_id;

        if (! $this->network->labIsVisible($attributes['branch_id'])) {
            throw ValidationException::withMessages(['branch_id' => 'The signatory must sign at a lab.']);
        }

        $this->assertDisciplineCovers($attributes['department_id'], SigningDiscipline::from($attributes['signing_discipline']));

        if (! $this->staffDirectory->isActiveStaffWithPermission($organizationId, $attributes['user_id'], Permission::SignReport)) {
            throw LabError::signatoryUserNotEligible();
        }

        $exists = Signatory::query()
            ->where('user_id', $attributes['user_id'])
            ->where('branch_id', $attributes['branch_id'])
            ->where('department_id', $attributes['department_id'])
            ->exists();

        if ($exists) {
            throw LabError::signatoryExists();
        }

        return DB::transaction(function () use ($organizationId, $attributes, $signatureImage): Signatory {
            $signatory = new Signatory($attributes);
            $signatory->id = $signatory->newUniqueId();
            $signatory->organization_id = $organizationId;
            $signatory->signature_image_path = $this->files->putNew(PrivatePaths::signature($signatory->id), (string) $signatureImage->get())->path;
            $signatory->save();
            $this->auditLogger->recordCreated('signatory.create', $signatory);

            return $signatory;
        });
    }

    /** @param  array<string, mixed>  $changes */
    public function update(Signatory $signatory, array $changes): Signatory
    {
        if (isset($changes['signing_discipline'])) {
            $this->assertDisciplineCovers($signatory->department_id, SigningDiscipline::from($changes['signing_discipline']));
        }

        return DB::transaction(function () use ($signatory, $changes): Signatory {
            $signatory->fill($changes)->save();
            $this->auditLogger->recordChanges('signatory.update', $signatory);

            return $signatory;
        });
    }

    /** Past signatures stay valid: the row is soft-deleted, never removed. */
    public function delete(Signatory $signatory): void
    {
        DB::transaction(function () use ($signatory): void {
            $signatory->delete();
            $this->auditLogger->record('signatory.delete', $signatory, ['is_active' => $signatory->is_active]);
        });
    }

    /**
     * The user's signatory rows in the organization, wherever the lab is.
     *
     * @return list<SignatoryCredential>
     */
    public function credentialsOf(string $organizationId, string $userId): array
    {
        return $this->currentScope->runAs(ScopeContext::system($organizationId), fn (): array => Signatory::query()
            ->where('user_id', $userId)
            ->get()
            ->map(fn (Signatory $signatory) => new SignatoryCredential(
                $signatory->id,
                $signatory->user_id,
                $signatory->branch_id,
                $signatory->department_id,
                $signatory->signing_discipline,
                $signatory->valid_till,
                $signatory->is_active,
            ))
            ->values()
            ->all());
    }

    /**
     * Signatory auto-disable (spec §9, daily): rows past `valid_till` stop
     * signing until HQ renews them.
     *
     * @return int signatories disabled
     */
    public function disableExpired(CarbonImmutable $today): int
    {
        $expired = Signatory::query()
            ->where('is_active', true)
            ->whereDate('valid_till', '<', $today->toDateString())
            ->get();

        foreach ($expired as $signatory) {
            DB::transaction(function () use ($signatory): void {
                $signatory->update(['is_active' => false]);
                $this->auditLogger->recordChanges('signatory.auto_disable', $signatory);
            });
        }

        return $expired->count();
    }

    private function assertDisciplineCovers(string $departmentId, SigningDiscipline $discipline): void
    {
        $department = $this->tests->departments([$departmentId])[$departmentId]
            ?? throw ValidationException::withMessages(['department_id' => 'The selected department does not exist.']);

        if (! SignatoryEligibility::disciplineCovers($discipline, $department->signingDiscipline)) {
            throw LabError::disciplineMismatch();
        }
    }
}
