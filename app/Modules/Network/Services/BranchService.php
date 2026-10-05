<?php

namespace App\Modules\Network\Services;

use App\Modules\Auth\Services\StaffDirectory;
use App\Modules\Network\Enums\BranchOwnerType;
use App\Modules\Network\Enums\BranchStatus;
use App\Modules\Network\Enums\BranchType;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\StateMachines\BranchStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Errors\DomainError;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BranchService
{
    /** Accreditation fields that only make sense on a lab (spec §7.1). */
    private const LAB_ONLY_FIELDS = ['nabl_certificate_no', 'nabl_valid_till'];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NetworkDirectory $directory,
        private readonly StaffDirectory $staffDirectory,
        private readonly BranchStateMachine $stateMachine,
    ) {}

    /**
     * New branches start in `setup` and go live through activate().
     *
     * @param  array<string, mixed>  $attributes  validated fields
     */
    public function create(string $organizationId, array $attributes): Branch
    {
        $this->assertRegionIsVisible($attributes['region_id']);
        $this->assertOwnership(BranchOwnerType::from($attributes['owner_type']), $attributes['franchise_id'] ?? null);
        $this->assertLabOnlyFields(BranchType::from($attributes['branch_type']), $attributes);

        return DB::transaction(function () use ($organizationId, $attributes): Branch {
            $branch = new Branch($attributes);
            $branch->organization_id = $organizationId;
            $branch->status = BranchStatus::Setup;
            $branch->save();
            $this->auditLogger->recordCreated('branch.create', $branch);

            return $branch;
        });
    }

    /**
     * Code, ownership and status are not editable here: the code is printed on
     * invoices and barcodes, ownership changes are a franchise process, and
     * status moves through the state machine.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(Branch $branch, array $changes): Branch
    {
        if (array_key_exists('region_id', $changes)) {
            $this->assertRegionIsVisible($changes['region_id']);
        }

        $branchType = array_key_exists('branch_type', $changes) ? BranchType::from($changes['branch_type']) : $branch->branch_type;
        $this->assertLabOnlyFields($branchType, $changes + $branch->only(self::LAB_ONLY_FIELDS));

        return DB::transaction(function () use ($branch, $changes): Branch {
            $branch->fill($changes)->save();
            $this->auditLogger->recordChanges('branch.update', $branch);

            return $branch;
        });
    }

    public function changeStatus(Branch $branch, BranchStatus $status): Branch
    {
        $this->stateMachine->transition($branch, $status);

        return $branch;
    }

    /** Only a branch that never went live and has no staff can be deleted; others are closed. */
    public function delete(Branch $branch): void
    {
        if ($branch->status !== BranchStatus::Setup || $this->staffDirectory->hasStaffAtBranch($branch->id)) {
            throw new DomainError(
                'BRANCH_NOT_DELETABLE',
                'Only branches still in setup with no staff can be deleted. Close the branch instead.',
                409,
            );
        }

        DB::transaction(function () use ($branch): void {
            $branch->delete();
            $this->auditLogger->record('branch.delete', $branch);
        });
    }

    private function assertRegionIsVisible(string $regionId): void
    {
        if (! $this->directory->regionIsVisible($regionId)) {
            throw ValidationException::withMessages(['region_id' => 'The selected region does not exist.']);
        }
    }

    private function assertOwnership(BranchOwnerType $ownerType, ?string $franchiseId): void
    {
        if ($ownerType === BranchOwnerType::Company && $franchiseId !== null) {
            throw ValidationException::withMessages(['franchise_id' => 'A company-owned branch cannot belong to a franchise.']);
        }

        if ($ownerType === BranchOwnerType::Franchise && $franchiseId === null) {
            throw ValidationException::withMessages(['franchise_id' => 'A franchise branch needs its franchise.']);
        }

        if ($franchiseId !== null && ! $this->directory->franchiseIsVisible($franchiseId)) {
            throw ValidationException::withMessages(['franchise_id' => 'The selected franchise does not exist.']);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertLabOnlyFields(BranchType $branchType, array $attributes): void
    {
        if ($branchType->isLab()) {
            return;
        }

        foreach (self::LAB_ONLY_FIELDS as $field) {
            if (($attributes[$field] ?? null) !== null) {
                throw ValidationException::withMessages([$field => 'Only lab branches carry NABL accreditation.']);
            }
        }
    }
}
