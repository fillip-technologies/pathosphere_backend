<?php

namespace App\Modules\Network\Services;

use App\Modules\Auth\Permissions\Permission;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Catalogue\Enums\PriceListType;
use App\Modules\Catalogue\Services\PriceListDirectory;
use App\Modules\Network\Enums\B2bClientStatus;
use App\Modules\Network\Errors\NetworkError;
use App\Modules\Network\Models\B2bClient;
use App\Modules\Network\StateMachines\B2bClientStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Hospitals, clinics, labs and corporates buying on credit (spec §7.1, §8 B2B). */
final class B2bClientService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NetworkDirectory $directory,
        private readonly PriceListDirectory $priceLists,
        private readonly B2bClientStateMachine $stateMachine,
    ) {}

    /** @param  array<string, mixed>  $attributes  validated fields */
    public function create(StaffContext $staff, array $attributes): B2bClient
    {
        $this->assertPlacement($attributes['region_id'], $attributes['serviced_by_branch_id']);
        $this->assertClientPriceList($attributes['price_list_id']);

        if (! Money::fromString((string) ($attributes['credit_limit'] ?? '0'))->isZero() && ! $staff->has(Permission::PostLedgerAdjustment)) {
            throw NetworkError::creditLimitNeedsFinance();
        }

        return DB::transaction(function () use ($staff, $attributes): B2bClient {
            $client = new B2bClient($attributes);
            $client->organization_id = $staff->user()->organization_id;
            $client->status = B2bClientStatus::Active;
            $client->save();
            $this->auditLogger->recordCreated('b2b_client.create', $client);

            return $client->refresh();
        });
    }

    /** @param  array<string, mixed>  $changes */
    public function update(StaffContext $staff, B2bClient $client, array $changes): B2bClient
    {
        $this->assertPlacement($changes['region_id'] ?? $client->region_id, $changes['serviced_by_branch_id'] ?? $client->serviced_by_branch_id);

        if (array_key_exists('price_list_id', $changes)) {
            $this->assertClientPriceList($changes['price_list_id']);
        }

        $limitChanges = array_key_exists('credit_limit', $changes) && ! Money::fromString((string) $changes['credit_limit'])->equals($client->credit_limit);

        if ($limitChanges && ! $staff->has(Permission::PostLedgerAdjustment)) {
            throw NetworkError::creditLimitNeedsFinance();
        }

        return DB::transaction(function () use ($client, $changes): B2bClient {
            $client->fill($changes)->save();
            $this->auditLogger->recordChanges('b2b_client.update', $client);

            return $client;
        });
    }

    public function changeStatus(B2bClient $client, B2bClientStatus $status): B2bClient
    {
        $this->stateMachine->transition($client, $status);

        return $client;
    }

    private function assertPlacement(string $regionId, string $branchId): void
    {
        if (! $this->directory->regionIsVisible($regionId)) {
            throw ValidationException::withMessages(['region_id' => 'The selected region does not exist.']);
        }

        if (! $this->directory->branchIsVisible($branchId)) {
            throw ValidationException::withMessages(['serviced_by_branch_id' => 'The selected branch does not exist.']);
        }
    }

    private function assertClientPriceList(string $priceListId): void
    {
        if (! $this->priceLists->isListOfType($priceListId, PriceListType::Client)) {
            throw ValidationException::withMessages(['price_list_id' => 'Choose an existing client price list.']);
        }
    }
}
