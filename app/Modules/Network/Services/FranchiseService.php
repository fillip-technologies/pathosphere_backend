<?php

namespace App\Modules\Network\Services;

use App\Modules\Auth\Permissions\Permission;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Catalogue\Enums\PriceListType;
use App\Modules\Catalogue\Services\PriceListDirectory;
use App\Modules\Network\Enums\AgreementStatus;
use App\Modules\Network\Enums\FranchiseStatus;
use App\Modules\Network\Errors\NetworkError;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\FranchiseAgreement;
use App\Modules\Network\Models\FranchiseDocument;
use App\Modules\Network\StateMachines\AgreementStateMachine;
use App\Modules\Network\StateMachines\FranchiseStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Franchise records and their lifecycle (spec §5.1). Status moves only
 * through FranchiseStateMachine; KYC approval and agreement activation call
 * back into this service when they complete a step.
 */
final class FranchiseService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NetworkDirectory $directory,
        private readonly PriceListDirectory $priceLists,
        private readonly FranchiseStateMachine $stateMachine,
        private readonly AgreementStateMachine $agreementStates,
    ) {}

    /**
     * Step 1: HQ registers the applicant (`applied`).
     *
     * @param  array<string, mixed>  $attributes  validated fields
     */
    public function create(StaffContext $staff, array $attributes): Franchise
    {
        $this->assertRegionIsVisible($attributes['region_id']);
        $this->assertPartnerPriceList($attributes['partner_price_list_id'] ?? null);
        $this->assertCreditLimitChangeAllowed($staff, $attributes);

        return DB::transaction(function () use ($staff, $attributes): Franchise {
            $franchise = new Franchise($attributes);
            $franchise->organization_id = $staff->user()->organization_id;
            $franchise->status = FranchiseStatus::Applied;
            $franchise->save();
            $this->auditLogger->recordCreated('franchise.create', $franchise);

            return $franchise;
        });
    }

    /**
     * Code, status and balance are not editable here: the code is printed on
     * documents, status moves through the state machine and the balance is
     * the ledger's.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(StaffContext $staff, Franchise $franchise, array $changes): Franchise
    {
        if (array_key_exists('region_id', $changes)) {
            $this->assertRegionIsVisible($changes['region_id']);
        }

        $this->assertPartnerPriceList($changes['partner_price_list_id'] ?? null);

        $limitChanges = array_key_exists('credit_limit', $changes) && ! Money::fromString((string) $changes['credit_limit'])->equals($franchise->credit_limit);

        if ($limitChanges && ! $staff->has(Permission::PostLedgerAdjustment)) {
            throw NetworkError::creditLimitNeedsFinance();
        }

        return DB::transaction(function () use ($franchise, $changes): Franchise {
            $franchise->fill($changes)->save();
            $this->auditLogger->recordChanges('franchise.update', $franchise);

            return $franchise;
        });
    }

    public function delete(Franchise $franchise): void
    {
        $hasHistory = Branch::query()->where('franchise_id', $franchise->id)->withTrashed()->exists()
            || FranchiseDocument::query()->where('franchise_id', $franchise->id)->exists()
            || FranchiseAgreement::query()->where('franchise_id', $franchise->id)->withTrashed()->exists();

        if ($franchise->status !== FranchiseStatus::Applied || $hasHistory) {
            throw NetworkError::franchiseNotDeletable();
        }

        DB::transaction(function () use ($franchise): void {
            $franchise->delete();
            $this->auditLogger->record('franchise.delete', $franchise);
        });
    }

    public function suspend(Franchise $franchise): Franchise
    {
        $this->stateMachine->transition($franchise, FranchiseStatus::Suspended);

        return $franchise;
    }

    /**
     * Suspended → active once the issue is cleared, or approved → active by
     * hand when the automatic go-live was missed. Either way the franchise
     * must be ready to trade.
     */
    public function activate(Franchise $franchise): Franchise
    {
        if ($franchise->status === FranchiseStatus::Approved) {
            $this->assertReadyToGoLive($franchise);
        }

        $this->stateMachine->transition($franchise, FranchiseStatus::Active, $this->firstActivationFields($franchise));

        return $franchise;
    }

    /** Any status → terminated; the live agreement ends with it (spec §5.1). */
    public function terminate(Franchise $franchise): Franchise
    {
        return DB::transaction(function () use ($franchise): Franchise {
            $this->stateMachine->transition($franchise, FranchiseStatus::Terminated);

            $liveAgreement = FranchiseAgreement::query()->where('franchise_id', $franchise->id)->where('status', AgreementStatus::Active)->first();
            if ($liveAgreement !== null) {
                $this->agreementStates->transition($liveAgreement, AgreementStatus::Terminated);
            }

            return $franchise;
        });
    }

    /**
     * Step 7: an approved franchise goes live by itself once its agreement is
     * active and it has a branch. Called after either happens; a no-op until
     * both are true.
     */
    public function activateIfReady(string $franchiseId): void
    {
        $franchise = Franchise::query()->lockForUpdate()->find($franchiseId);

        if ($franchise === null || $franchise->status !== FranchiseStatus::Approved || $this->missingForGoLive($franchise) !== []) {
            return;
        }

        $this->stateMachine->transition($franchise, FranchiseStatus::Active, $this->firstActivationFields($franchise));
    }

    /** Step 2 complete: every mandatory KYC paper is verified. */
    public function approveKyc(Franchise $franchise): void
    {
        if ($franchise->status === FranchiseStatus::KycPending) {
            $this->stateMachine->transition($franchise, FranchiseStatus::Approved);
        }
    }

    /** First paper uploaded: the application moves to KYC review. */
    public function startKyc(Franchise $franchise): void
    {
        if ($franchise->status === FranchiseStatus::Applied) {
            $this->stateMachine->transition($franchise, FranchiseStatus::KycPending);
        }
    }

    private function assertReadyToGoLive(Franchise $franchise): void
    {
        $missing = $this->missingForGoLive($franchise);

        if ($missing !== []) {
            throw NetworkError::franchiseNotReady($missing);
        }
    }

    /** @return list<string> */
    private function missingForGoLive(Franchise $franchise): array
    {
        $missing = [];

        if (! FranchiseAgreement::query()->where('franchise_id', $franchise->id)->where('status', AgreementStatus::Active)->exists()) {
            $missing[] = 'active_agreement';
        }

        if (! Branch::query()->where('franchise_id', $franchise->id)->exists()) {
            $missing[] = 'branch';
        }

        return $missing;
    }

    /** @return array<string, mixed> */
    private function firstActivationFields(Franchise $franchise): array
    {
        return $franchise->onboarded_at === null ? ['onboarded_at' => CarbonImmutable::now()] : [];
    }

    private function assertRegionIsVisible(string $regionId): void
    {
        if (! $this->directory->regionIsVisible($regionId)) {
            throw ValidationException::withMessages(['region_id' => 'The selected region does not exist.']);
        }
    }

    private function assertPartnerPriceList(?string $priceListId): void
    {
        if ($priceListId !== null && ! $this->priceLists->isListOfType($priceListId, PriceListType::Partner)) {
            throw ValidationException::withMessages(['partner_price_list_id' => 'Choose an existing partner price list.']);
        }
    }

    /**
     * Credit limits decide how far a wallet may go negative, so only head
     * office finance grants one (spec §12: auto-hold blocks only with HQ Finance).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertCreditLimitChangeAllowed(StaffContext $staff, array $attributes): void
    {
        $limit = $attributes['credit_limit'] ?? null;

        if ($limit !== null && ! Money::fromString((string) $limit)->isZero() && ! $staff->has(Permission::PostLedgerAdjustment)) {
            throw NetworkError::creditLimitNeedsFinance();
        }
    }
}
