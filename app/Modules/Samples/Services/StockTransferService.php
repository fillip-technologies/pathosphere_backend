<?php

namespace App\Modules\Samples\Services;

use App\Modules\Ledger\Services\KitSupplyCharges;
use App\Modules\Network\Services\BranchOwnership;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Samples\Domain\StockQuantity;
use App\Modules\Samples\Enums\InventoryCategory;
use App\Modules\Samples\Enums\StockTransferStatus;
use App\Modules\Samples\Errors\SampleError;
use App\Modules\Samples\Models\InventoryItem;
use App\Modules\Samples\Models\StockTransfer;
use App\Modules\Samples\StateMachines\StockTransferStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;
use App\Modules\Shared\Numbering\NumberSequenceService;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supplying a branch with stock (spec §7.7): requested by either end,
 * dispatched by the sender (stock leaves its shelf), received by the
 * receiver (stock lands on its shelf, and a franchise is charged for kits
 * from HQ). Cancelling a dispatched transfer puts the stock back.
 *
 * Stock rows of the other branch are read and written at organization level:
 * the transfer itself is the caller's proof that it may move them.
 */
final class StockTransferService
{
    private const DEFAULT_NUMBER_FORMAT = 'ST-{branch_code}-{seq:6}';

    public function __construct(
        private readonly BranchGuard $branchGuard,
        private readonly NetworkDirectory $network,
        private readonly NumberSequenceService $sequences,
        private readonly StockTransferStateMachine $stateMachine,
        private readonly KitSupplyCharges $kitCharges,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $auditLogger,
        private readonly CurrentScope $currentScope,
    ) {}

    /** @param  array<string, mixed>  $attributes  validated fields */
    public function request(string $organizationId, array $attributes): StockTransfer
    {
        $from = $this->branch($organizationId, $attributes['from_branch_id'], 'from_branch_id');
        $to = $this->branch($organizationId, $attributes['to_branch_id'], 'to_branch_id');
        $actsForSender = $this->actsFor($from->branchId);

        if (! $actsForSender && ! $this->actsFor($to->branchId)) {
            throw SampleError::notForThisBranch('move stock for');
        }

        $charge = Money::fromString((string) ($attributes['charge_amount'] ?? '0'));
        $this->assertChargeAllowed($from, $to, $charge, $actsForSender);

        $senderItem = $this->stockItem($organizationId, $from->branchId, $attributes['item_code'], $attributes['batch_no'])
            ?? throw SampleError::senderItemMissing();

        return DB::transaction(function () use ($organizationId, $attributes, $from, $senderItem, $charge): StockTransfer {
            $transfer = new StockTransfer([
                'from_branch_id' => $from->branchId,
                'to_branch_id' => $attributes['to_branch_id'],
                'item_code' => $senderItem->item_code,
                'item_name' => $senderItem->name,
                'batch_no' => $senderItem->batch_no,
                'quantity' => (string) StockQuantity::fromString((string) $attributes['quantity']),
                'charge_amount' => $charge,
                'status' => StockTransferStatus::Requested,
            ]);
            $transfer->organization_id = $organizationId;
            $transfer->transfer_no = $this->transferNumber($organizationId, $from);
            $transfer->save();
            $this->auditLogger->recordCreated('stock_transfer.create', $transfer);

            return $transfer;
        });
    }

    /** @param  array<string, mixed>  $changes  quantity and/or charge_amount */
    public function update(StockTransfer $transfer, array $changes): StockTransfer
    {
        if ($transfer->status !== StockTransferStatus::Requested) {
            throw SampleError::transferNotEditable();
        }

        $actsForSender = $this->actsFor($transfer->from_branch_id);

        if (array_key_exists('charge_amount', $changes)) {
            $this->assertChargeAllowed(
                $this->branch($transfer->organization_id, $transfer->from_branch_id, 'from_branch_id'),
                $this->branch($transfer->organization_id, $transfer->to_branch_id, 'to_branch_id'),
                Money::fromString((string) $changes['charge_amount']),
                $actsForSender,
            );
        }

        return DB::transaction(function () use ($transfer, $changes): StockTransfer {
            if (array_key_exists('quantity', $changes)) {
                $changes['quantity'] = (string) StockQuantity::fromString((string) $changes['quantity']);
            }

            $transfer->fill($changes)->save();
            $this->auditLogger->recordChanges('stock_transfer.update', $transfer);

            return $transfer;
        });
    }

    /** The sender ships it: the batch leaves the sender's stock. */
    public function dispatch(StockTransfer $transfer, ?Money $charge): StockTransfer
    {
        $this->branchGuard->assertActsFor($transfer->from_branch_id, 'dispatch stock from');

        if ($charge !== null) {
            $this->assertChargeAllowed(
                $this->branch($transfer->organization_id, $transfer->from_branch_id, 'from_branch_id'),
                $this->branch($transfer->organization_id, $transfer->to_branch_id, 'to_branch_id'),
                $charge,
                true,
            );
        }

        return DB::transaction(function () use ($transfer, $charge): StockTransfer {
            $item = $this->lockStockItem($transfer->organization_id, $transfer->from_branch_id, $transfer->item_code, $transfer->batch_no)
                ?? throw SampleError::senderItemMissing();
            $onShelf = StockQuantity::fromString($item->quantity);
            $moving = StockQuantity::fromString($transfer->quantity);

            if ($onShelf->isLessThan($moving)) {
                throw SampleError::stockInsufficient((string) $onShelf);
            }

            $this->setQuantity($item, $onShelf->subtract($moving), "stock_transfer.dispatch:{$transfer->transfer_no}");
            $this->stateMachine->transition($transfer, StockTransferStatus::Dispatched, array_filter([
                'dispatched_at' => CarbonImmutable::now(),
                'charge_amount' => $charge,
            ], fn ($value) => $value !== null));

            $this->alertIfBelowReorderLevel($item);

            return $transfer;
        });
    }

    /**
     * The receiver scans it in: the batch lands on its shelf, and a kit
     * supply from HQ to a franchise branch is debited to the franchise
     * (spec §7.8). All in one transaction.
     */
    public function receive(StockTransfer $transfer): StockTransfer
    {
        $this->branchGuard->assertActsFor($transfer->to_branch_id, 'receive stock at');
        $to = $this->branch($transfer->organization_id, $transfer->to_branch_id, 'to_branch_id');

        return DB::transaction(function () use ($transfer, $to): StockTransfer {
            $this->stateMachine->transition($transfer, StockTransferStatus::Received, ['received_at' => CarbonImmutable::now()]);

            $receiverItem = $this->lockStockItem($transfer->organization_id, $transfer->to_branch_id, $transfer->item_code, $transfer->batch_no);
            $moving = StockQuantity::fromString($transfer->quantity);

            if ($receiverItem === null) {
                $this->createReceiverItem($transfer, $moving);
            } else {
                $this->setQuantity($receiverItem, StockQuantity::fromString($receiverItem->quantity)->add($moving), "stock_transfer.receive:{$transfer->transfer_no}");
            }

            if ($to->franchiseId !== null && ! $transfer->charge_amount->isZero()) {
                $this->kitCharges->chargeReceived($transfer->organization_id, $to->franchiseId, $transfer->id, $transfer->transfer_no, $transfer->charge_amount);
            }

            return $transfer;
        });
    }

    public function cancel(StockTransfer $transfer, string $reason): StockTransfer
    {
        $actsForSender = $this->actsFor($transfer->from_branch_id);

        if (! $actsForSender && ! $this->actsFor($transfer->to_branch_id)) {
            throw SampleError::notForThisBranch('cancel stock transfers for');
        }

        return DB::transaction(function () use ($transfer, $reason): StockTransfer {
            $wasDispatched = $transfer->status === StockTransferStatus::Dispatched;
            $this->stateMachine->transition($transfer, StockTransferStatus::Cancelled, ['cancelled_reason' => $reason]);

            if ($wasDispatched) {
                $item = $this->lockStockItem($transfer->organization_id, $transfer->from_branch_id, $transfer->item_code, $transfer->batch_no);

                if ($item !== null) {
                    $this->setQuantity($item, StockQuantity::fromString($item->quantity)->add(StockQuantity::fromString($transfer->quantity)), "stock_transfer.cancel:{$transfer->transfer_no}");
                }
            }

            return $transfer;
        });
    }

    private function createReceiverItem(StockTransfer $transfer, StockQuantity $quantity): void
    {
        // The sender's batch says what the item is; if the sender has since removed its empty row, fall back to a plain kit.
        $source = $this->stockItem($transfer->organization_id, $transfer->from_branch_id, $transfer->item_code, $transfer->batch_no);

        $this->currentScope->runAs(ScopeContext::system($transfer->organization_id), function () use ($transfer, $quantity, $source): void {
            $item = new InventoryItem([
                'branch_id' => $transfer->to_branch_id,
                'item_code' => $transfer->item_code,
                'name' => $transfer->item_name,
                'category' => $source->category ?? InventoryCategory::Kit,
                'unit' => $source->unit ?? 'unit',
                'quantity' => (string) $quantity,
                'batch_no' => $transfer->batch_no,
                'expiry_date' => $source?->expiry_date,
            ]);
            $item->organization_id = $transfer->organization_id;
            $item->save();
            $this->auditLogger->recordCreated('inventory_item.create', $item);
        });
    }

    private function setQuantity(InventoryItem $item, StockQuantity $quantity, string $reason): void
    {
        $this->currentScope->runAs(ScopeContext::system($item->organization_id), function () use ($item, $quantity, $reason): void {
            $item->forceFill(['quantity' => (string) $quantity])->save();
            $this->auditLogger->record('inventory_item.quantity', $item, ['quantity' => $item->getPrevious()['quantity'] ?? null], ['quantity' => (string) $quantity, 'reason' => $reason]);
        });
    }

    private function alertIfBelowReorderLevel(InventoryItem $item): void
    {
        if ($item->reorder_level === null || ! StockQuantity::fromString($item->quantity)->isLessThan(StockQuantity::fromString($item->reorder_level))) {
            return;
        }

        $branch = $this->network->branchContact($item->branch_id);
        $this->notifications->notify('stock_low', new NotificationRecipient('branch', $branch->branchId, $branch->organizationId, $branch->phone, null, false), [
            'branch_name' => $branch->name,
            'item_name' => $item->name,
            'batch_no' => $item->batch_no,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
        ]);
    }

    /** Only HQ charges, only franchises are charged, and only the sending side sets the price. */
    private function assertChargeAllowed(BranchOwnership $from, BranchOwnership $to, Money $charge, bool $actsForSender): void
    {
        if ($charge->isZero()) {
            return;
        }

        if ($charge->isNegative() || ! $actsForSender || ! $from->isCompanyOwned() || $to->isCompanyOwned()) {
            throw SampleError::chargeNotAllowed();
        }
    }

    private function actsFor(string $branchId): bool
    {
        return $this->currentScope->get()?->coversBranch($branchId) ?? false;
    }

    private function branch(string $organizationId, string $branchId, string $field): BranchOwnership
    {
        return $this->network->branchOwnership($organizationId, $branchId)
            ?? throw ValidationException::withMessages([$field => 'The selected branch does not exist.']);
    }

    private function stockItem(string $organizationId, string $branchId, string $itemCode, string $batchNo): ?InventoryItem
    {
        return $this->currentScope->runAs(ScopeContext::system($organizationId), fn () => InventoryItem::query()
            ->where('branch_id', $branchId)
            ->where('item_code', $itemCode)
            ->where('batch_no', $batchNo)
            ->first());
    }

    private function lockStockItem(string $organizationId, string $branchId, string $itemCode, string $batchNo): ?InventoryItem
    {
        return $this->currentScope->runAs(ScopeContext::system($organizationId), fn () => InventoryItem::query()
            ->where('branch_id', $branchId)
            ->where('item_code', $itemCode)
            ->where('batch_no', $batchNo)
            ->lockForUpdate()
            ->first());
    }

    private function transferNumber(string $organizationId, BranchOwnership $from): string
    {
        $format = (string) ($this->network->organizationSettings($organizationId)['stock_transfer_number_format'] ?? self::DEFAULT_NUMBER_FORMAT);

        return $this->sequences->nextFormatted($organizationId, "stock_transfer:{$from->branchId}", $format, ['branch_code' => $from->branchCode]);
    }
}
