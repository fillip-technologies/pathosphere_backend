<?php

namespace App\Modules\Samples\Services;

use App\Modules\Samples\Errors\SampleError;
use App\Modules\Samples\Models\InventoryItem;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Illuminate\Support\Facades\DB;

/** Branch stock (spec §7.7): batches of reagents, tubes and kits. */
final class InventoryService
{
    public function __construct(
        private readonly BranchGuard $branchGuard,
        private readonly AuditLogger $auditLogger,
        private readonly CurrentScope $currentScope,
    ) {}

    /** @param  array<string, mixed>  $attributes  validated fields */
    public function create(string $organizationId, array $attributes): InventoryItem
    {
        $this->branchGuard->assertActsFor($attributes['branch_id'], 'keep stock for');

        $exists = $this->currentScope->runAs(ScopeContext::system($organizationId), fn () => InventoryItem::query()
            ->where('branch_id', $attributes['branch_id'])
            ->where('item_code', $attributes['item_code'])
            ->where('batch_no', $attributes['batch_no'])
            ->exists());

        if ($exists) {
            throw SampleError::inventoryItemExists();
        }

        return DB::transaction(function () use ($organizationId, $attributes): InventoryItem {
            $item = new InventoryItem($attributes);
            $item->organization_id = $organizationId;
            $item->save();
            $this->auditLogger->recordCreated('inventory_item.create', $item);

            return $item;
        });
    }

    /**
     * Branch, code and batch identify the stock and never change; a stock
     * count sets the quantity directly (audited).
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(InventoryItem $item, array $changes): InventoryItem
    {
        $this->branchGuard->assertActsFor($item->branch_id, 'keep stock for');

        return DB::transaction(function () use ($item, $changes): InventoryItem {
            $item->fill($changes)->save();
            $this->auditLogger->recordChanges('inventory_item.update', $item);

            return $item;
        });
    }

    public function delete(InventoryItem $item): void
    {
        $this->branchGuard->assertActsFor($item->branch_id, 'keep stock for');

        if (bccomp($item->quantity, '0', 2) !== 0) {
            throw SampleError::inventoryItemInStock();
        }

        DB::transaction(function () use ($item): void {
            $item->delete();
            $this->auditLogger->record('inventory_item.delete', $item);
        });
    }
}
