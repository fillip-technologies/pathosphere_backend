<?php

namespace App\Modules\Network\Services;

use App\Modules\Network\Enums\BranchStatus;
use App\Modules\Network\Enums\BranchType;
use App\Modules\Network\Enums\FranchiseStatus;
use App\Modules\Network\Models\B2bClient;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\Organization;
use App\Modules\Network\Models\Region;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;

/**
 * Read-only questions other modules ask about the network. Every query runs
 * under the caller's current scope, so "exists" means "exists and is visible
 * to you".
 */
final class NetworkDirectory
{
    public function __construct(private readonly CurrentScope $currentScope) {}

    /**
     * The region and all regions below it.
     *
     * @return list<string>
     */
    public function regionTreeIds(string $regionId): array
    {
        $treeIds = [$regionId];
        $frontier = [$regionId];

        while ($frontier !== []) {
            $frontier = Region::query()->whereIn('parent_region_id', $frontier)->pluck('id')->all();
            $treeIds = [...$treeIds, ...$frontier];
        }

        return $treeIds;
    }

    /**
     * @param  list<string>  $regionIds
     * @return list<string>
     */
    public function franchiseIdsInRegions(array $regionIds): array
    {
        return Franchise::query()->whereIn('region_id', $regionIds)->pluck('id')->all();
    }

    /**
     * @param  list<string>  $regionIds
     * @return list<string>
     */
    public function branchIdsInRegions(array $regionIds): array
    {
        return Branch::query()->whereIn('region_id', $regionIds)->pluck('id')->all();
    }

    /** @return list<string> */
    public function branchIdsOfFranchise(string $franchiseId): array
    {
        return Branch::query()->where('franchise_id', $franchiseId)->pluck('id')->all();
    }

    public function regionIsVisible(string $regionId): bool
    {
        return Region::query()->whereKey($regionId)->exists();
    }

    public function franchiseIsVisible(string $franchiseId): bool
    {
        return Franchise::query()->whereKey($franchiseId)->exists();
    }

    public function branchIsVisible(string $branchId): bool
    {
        return Branch::query()->whereKey($branchId)->exists();
    }

    public function b2bClientIsVisible(string $b2bClientId): bool
    {
        return B2bClient::query()->whereKey($b2bClientId)->exists();
    }

    /** A lab branch (reference or clinical) the caller can see. */
    public function labIsVisible(string $branchId): bool
    {
        return Branch::query()
            ->whereKey($branchId)
            ->whereIn('branch_type', [BranchType::ReferenceLab, BranchType::ClinicalLab])
            ->exists();
    }

    /** @return list<string> branch IDs the caller can see */
    public function visibleBranchIds(): array
    {
        return Branch::query()->pluck('id')->all();
    }

    /** Pricing facts for a branch the caller can see; null when it is not visible. */
    public function pricingProfile(string $branchId): ?BranchPricingProfile
    {
        $branch = Branch::query()->find($branchId);

        if ($branch === null) {
            return null;
        }

        return new BranchPricingProfile(
            $branch->id,
            $branch->organization_id,
            $branch->status,
            $branch->mrp_price_list_id,
            $branch->franchise_id,
            $this->partnerPriceListOf($branch),
        );
    }

    /** The client's price list, or null when the client is not visible to the caller. */
    public function b2bClientPriceListId(string $b2bClientId): ?string
    {
        return B2bClient::query()->whereKey($b2bClientId)->value('price_list_id');
    }

    /**
     * Organization settings (number formats, policies). Every staff member's
     * own organization; read at organization level because branch staff
     * cannot see the organization row's scope columns.
     *
     * @return array<string, mixed>
     */
    public function organizationSettings(string $organizationId): array
    {
        return $this->currentScope->runAs(
            ScopeContext::system($organizationId),
            fn (): array => Organization::query()->findOrFail($organizationId)->settings,
        );
    }

    /** The lab whose ABDM Health Facility Registry ID this is (Scan and Share callbacks). */
    public function branchIdByHfrId(string $hfrId): ?string
    {
        if ($hfrId === '') {
            return null;
        }

        return $this->currentScope->runAs(ScopeContext::system(), fn (): ?string => Branch::query()->where('hfr_id', $hfrId)->value('id'));
    }

    /** Branch code, used in invoice and order numbers. */
    public function branchCode(string $branchId): string
    {
        return $this->currentScope->runAs(
            ScopeContext::system(),
            fn (): string => (string) Branch::query()->whereKey($branchId)->value('branch_code'),
        );
    }

    /**
     * Name and phone for operational alerts to a branch (e.g. a sample
     * rejected or a manifest on its way). Any branch the network sends
     * samples to or from may be told.
     */
    public function branchContact(string $branchId): BranchContact
    {
        return $this->currentScope->runAs(ScopeContext::system(), function () use ($branchId): BranchContact {
            $branch = Branch::query()->findOrFail($branchId);

            return new BranchContact($branch->id, $branch->organization_id, $branch->branch_code, $branch->name, $branch->phone);
        });
    }

    /**
     * What a report prints about a site (spec §10: NABL and registration of
     * the processing lab, name of the collection centre).
     */
    public function letterhead(string $branchId): BranchLetterhead
    {
        return $this->currentScope->runAs(ScopeContext::system(), function () use ($branchId): BranchLetterhead {
            $branch = Branch::query()->withTrashed()->findOrFail($branchId);

            return new BranchLetterhead(
                $branch->id,
                $branch->branch_code,
                $branch->name,
                $branch->address,
                $branch->phone,
                $branch->nabl_certificate_no,
                $branch->nabl_valid_till,
                $branch->clinical_establishment_reg_no,
            );
        });
    }

    /** The brand printed on reports. */
    public function organizationName(string $organizationId): string
    {
        return $this->currentScope->runAs(
            ScopeContext::system($organizationId),
            fn (): string => (string) Organization::query()->whereKey($organizationId)->value('name'),
        );
    }

    /** Whether the client's reports wait while it has overdue invoices (spec §12: B2B only, per client). */
    public function b2bClientWithholdsReports(string $b2bClientId): bool
    {
        return $this->currentScope->runAs(
            ScopeContext::system(),
            fn (): bool => (bool) B2bClient::query()->whereKey($b2bClientId)->value('withhold_reports_when_overdue'),
        );
    }

    /** An active reference or clinical lab of the organization, visible to the caller or not. */
    public function isOperatingLab(string $organizationId, string $branchId): bool
    {
        return in_array($branchId, $this->operatingLabIds($organizationId), true);
    }

    /**
     * A suspended or terminated franchise's branches cannot take orders
     * (spec §5.1); company branches always can.
     */
    public function franchiseAllowsBooking(?string $franchiseId): bool
    {
        if ($franchiseId === null) {
            return true;
        }

        return $this->currentScope->runAs(
            ScopeContext::system(),
            fn (): bool => Franchise::query()->whereKey($franchiseId)->where('status', FranchiseStatus::Active)->exists(),
        );
    }

    /** Credit days of a visible B2B client, for invoice due dates. */
    public function b2bClientCreditDays(string $b2bClientId): int
    {
        return (int) B2bClient::query()->whereKey($b2bClientId)->value('credit_days');
    }

    /** Whether any branch, franchise or B2B client still uses the price list. */
    public function priceListIsAssigned(string $priceListId): bool
    {
        return Branch::query()->where('mrp_price_list_id', $priceListId)->exists()
            || Franchise::query()->where('partner_price_list_id', $priceListId)->exists()
            || B2bClient::query()->where('price_list_id', $priceListId)->exists();
    }

    /**
     * A franchise branch's staff have branch scope and cannot see the
     * franchise row itself, yet their bookings must carry its partner price.
     * The branch is already visible to the caller, so reading its franchise's
     * price list at organization level reveals nothing else.
     */
    private function partnerPriceListOf(Branch $branch): ?string
    {
        if ($branch->franchise_id === null) {
            return null;
        }

        return $this->currentScope->runAs(
            ScopeContext::system($branch->organization_id),
            fn (): ?string => Franchise::query()->whereKey($branch->franchise_id)->value('partner_price_list_id'),
        );
    }

    /**
     * Labs that can take work now, across the whole organization: a front
     * desk must route to labs outside its own scope. Only IDs leave here.
     *
     * @return list<string>
     */
    public function operatingLabIds(string $organizationId): array
    {
        return $this->currentScope->runAs(ScopeContext::system($organizationId), fn (): array => Branch::query()
            ->whereIn('branch_type', [BranchType::ReferenceLab, BranchType::ClinicalLab])
            ->where('status', BranchStatus::Active)
            ->pluck('id')
            ->all());
    }
}
