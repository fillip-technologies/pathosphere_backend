<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\OrderItemStatus;
use App\Modules\Booking\Enums\OrderStatus;
use App\Modules\Booking\Models\Order;
use App\Modules\Booking\Models\OrderItem;
use App\Modules\Booking\StateMachines\OrderStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the sample journey (spec §5.4) needs from orders: which tests still
 * need a container, and the item and order status changes that collection,
 * rejection and re-routing cause.
 *
 * Except for orderForSampling(), these methods are called for a sample the
 * caller can already see, which is the proof of access. They read the order
 * at organization level because a processing lab may not see the booking
 * branch's order (spec §4 special rule); only the facts below leave here,
 * never invoices or payments.
 */
final class OrderFulfilment
{
    private const SAMPLING_STATUSES = [OrderStatus::Confirmed, OrderStatus::InProgress, OrderStatus::PartiallyReported];

    public function __construct(
        private readonly CurrentScope $currentScope,
        private readonly AuditLogger $auditLogger,
        private readonly OrderStateMachine $orderStates,
    ) {}

    /**
     * An order visible to the caller, with its test lines still awaiting a
     * container. Null when the caller cannot see it. Lock it when about to
     * create samples, so two requests never draw the same tests twice.
     */
    public function orderForSampling(string $orderId, bool $lockForUpdate = false): ?SamplingOrder
    {
        $query = Order::query()->whereKey($orderId);
        $order = ($lockForUpdate ? $query->lockForUpdate() : $query)->first();

        if ($order === null) {
            return null;
        }

        $lines = OrderItem::query()
            ->where('order_id', $order->id)
            ->whereNotNull('test_id')
            ->where('status', OrderItemStatus::Ordered)
            ->orderBy('id')
            ->get()
            ->map(fn (OrderItem $item) => new SamplingOrderLine($item->id, (string) $item->test_id, (string) $item->processing_branch_id))
            ->values()
            ->all();

        return new SamplingOrder(
            $order->id,
            $order->organization_id,
            $order->branch_id,
            in_array($order->status, self::SAMPLING_STATUSES, true),
            $lines,
        );
    }

    /**
     * @param  list<string>  $orderIds
     * @return array<string, SampleOrderFacts> by order ID
     */
    public function factsForSamples(string $organizationId, array $orderIds): array
    {
        return $this->inOrganization($organizationId, fn (): array => Order::query()
            ->with(['patient' => fn ($patients) => $patients->withTrashed()])
            ->whereKey(array_values(array_unique($orderIds)))
            ->get()
            ->mapWithKeys(fn (Order $order) => [$order->id => new SampleOrderFacts(
                $order->id,
                $order->order_no,
                $order->status === OrderStatus::Cancelled,
                $order->patient->id,
                $order->patient->name,
                $order->patient->uhid,
                $order->patient->ageInYears(),
                $order->patient->gender->value,
            )])
            ->all());
    }

    /**
     * @param  list<string>  $orderItemIds
     * @return array<string, string> order item ID => test ID
     */
    public function testIdsOfItems(string $organizationId, array $orderItemIds): array
    {
        return $this->inOrganization($organizationId, fn (): array => OrderItem::query()
            ->whereKey($orderItemIds)
            ->whereNotNull('test_id')
            ->orderBy('id')
            ->pluck('test_id', 'id')
            ->all());
    }

    /** The order's patient as a message recipient, e.g. to ask them back for a redraw. */
    public function patientRecipient(string $organizationId, string $orderId): NotificationRecipient
    {
        return $this->inOrganization($organizationId, function () use ($organizationId, $orderId): NotificationRecipient {
            $patient = Order::query()->with(['patient' => fn ($patients) => $patients->withTrashed()])->findOrFail($orderId)->patient;

            return new NotificationRecipient('patient', $patient->id, $organizationId, $patient->phone, $patient->email, $patient->whatsapp_opted_in_at !== null);
        });
    }

    /**
     * A container was drawn: its tests move to `collected`, and the first
     * collection starts the order (confirmed → in_progress, spec §5.2).
     *
     * @param  list<string>  $orderItemIds
     */
    public function markItemsCollected(string $organizationId, string $orderId, array $orderItemIds): void
    {
        $this->inOrganization($organizationId, function () use ($orderId, $orderItemIds): void {
            DB::transaction(function () use ($orderId, $orderItemIds): void {
                OrderItem::query()
                    ->where('order_id', $orderId)
                    ->whereKey($orderItemIds)
                    ->where('status', OrderItemStatus::Ordered)
                    ->update(['status' => OrderItemStatus::Collected]);

                $order = Order::query()->lockForUpdate()->findOrFail($orderId);

                if ($order->status === OrderStatus::Confirmed) {
                    $this->orderStates->transition($order, OrderStatus::InProgress);
                }
            });
        });
    }

    /**
     * A rejected container's tests are drawn again at no charge (spec §5.4
     * step 5): each line is marked `recollect` and gets a free replacement
     * line that keeps the original turnaround time from now.
     *
     * @param  list<string>  $orderItemIds
     * @return list<string> the replacement order item IDs
     */
    public function replaceWithRecollection(string $organizationId, string $orderId, array $orderItemIds): array
    {
        return $this->inOrganization($organizationId, fn (): array => DB::transaction(function () use ($orderId, $orderItemIds): array {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $originals = OrderItem::query()->where('order_id', $order->id)->whereKey($orderItemIds)->orderBy('id')->get();
            $now = CarbonImmutable::now();
            $replacementIds = [];

            foreach ($originals as $original) {
                $original->update(['status' => OrderItemStatus::Recollect]);

                $replacement = new OrderItem([
                    'test_id' => $original->test_id,
                    'processing_branch_id' => $original->processing_branch_id,
                    'mrp_price' => Money::zero(),
                    'partner_price' => Money::zero(),
                    'discount' => Money::zero(),
                    'net_price' => Money::zero(),
                    'due_at' => $original->due_at === null ? null : $now->add($original->created_at->diff($original->due_at)),
                    'status' => OrderItemStatus::Ordered,
                ]);
                $replacement->order_id = $order->id;
                $replacement->recollection_of_item_id = $original->id;
                $replacement->save();
                $replacementIds[] = $replacement->id;
            }

            $this->auditLogger->record('order.recollection', $order, [], [
                'recollected_item_ids' => $originals->pluck('id')->all(),
                'replacement_item_ids' => $replacementIds,
            ]);

            return $replacementIds;
        }));
    }

    /**
     * A lab forwards a container elsewhere (spec §5.4 step 6): its tests are
     * now run, and reported, by the new lab.
     *
     * @param  list<string>  $orderItemIds
     */
    public function rerouteItems(string $organizationId, array $orderItemIds, string $processingBranchId): void
    {
        $this->inOrganization($organizationId, function () use ($orderItemIds, $processingBranchId): void {
            DB::transaction(function () use ($orderItemIds, $processingBranchId): void {
                foreach (OrderItem::query()->with('order')->whereKey($orderItemIds)->get() as $item) {
                    $previousLab = $item->processing_branch_id;
                    $item->update(['processing_branch_id' => $processingBranchId]);
                    $this->auditLogger->record('order_item.reroute', $item->order, ['order_item_id' => $item->id, 'processing_branch_id' => $previousLab], [
                        'order_item_id' => $item->id,
                        'processing_branch_id' => $processingBranchId,
                    ]);
                }
            });
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inOrganization(string $organizationId, callable $callback): mixed
    {
        return $this->currentScope->runAs(ScopeContext::system($organizationId), $callback);
    }
}
