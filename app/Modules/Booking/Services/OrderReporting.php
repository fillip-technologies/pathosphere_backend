<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\OrderItemStatus;
use App\Modules\Booking\Enums\OrderStatus;
use App\Modules\Booking\Enums\ReportDelivery;
use App\Modules\Booking\Models\Doctor;
use App\Modules\Booking\Models\Invoice;
use App\Modules\Booking\Models\Order;
use App\Modules\Booking\Models\OrderItem;
use App\Modules\Booking\StateMachines\OrderStateMachine;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the lab and its reports (spec §5.5) need from orders: the tests to
 * run, the patient facts that pick reference ranges, the details a report
 * prints, and the item and order status changes that results cause.
 *
 * Callers reach an order through a sample, worklist entry or report they can
 * already see, which is the proof of access; the order is read at
 * organization level because a processing lab cannot see another branch's
 * order (spec §4 special rule). No invoice or payment detail leaves here.
 */
final class OrderReporting
{
    private const NOT_REPORTED = [OrderItemStatus::Cancelled, OrderItemStatus::Recollect];

    public function __construct(
        private readonly CurrentScope $currentScope,
        private readonly OrderStateMachine $orderStates,
    ) {}

    /**
     * @param  list<string>  $orderItemIds
     * @return array<string, LabOrderItem> test lines only, by order item ID
     */
    public function labItems(string $organizationId, array $orderItemIds): array
    {
        return $this->inOrganization($organizationId, fn (): array => OrderItem::query()
            ->whereKey($orderItemIds)
            ->whereNotNull('test_id')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (OrderItem $item) => [$item->id => $this->labItem($item)])
            ->all());
    }

    public function reportFacts(string $organizationId, string $orderId): ReportOrderFacts
    {
        return $this->inOrganization($organizationId, function () use ($orderId): ReportOrderFacts {
            $order = Order::query()->with(['patient' => fn ($patients) => $patients->withTrashed()])->findOrFail($orderId);
            $patient = $order->patient;
            $doctorName = $order->doctor_id === null ? null : Doctor::query()->withTrashed()->whereKey($order->doctor_id)->value('name');

            return new ReportOrderFacts(
                $order->id,
                $order->organization_id,
                $order->order_no,
                $order->order_date,
                $order->branch_id,
                $order->franchise_id,
                $order->b2b_client_id,
                $patient->id,
                $patient->name,
                $patient->uhid,
                $patient->ageInYears(),
                $patient->dob !== null
                    ? (int) $patient->dob->diffInDays(CarbonImmutable::now(), absolute: true)
                    : ($patient->age_years === null ? null : $patient->age_years * 365),
                $patient->gender,
                $doctorName === null ? null : (string) $doctorName,
            );
        });
    }

    /**
     * Test lines of the order that the lab should report, whether or not
     * their samples have arrived yet.
     *
     * @return list<string>
     */
    public function reportableItemIdsAtLab(string $organizationId, string $orderId, string $labId): array
    {
        return $this->inOrganization($organizationId, fn (): array => OrderItem::query()
            ->where('order_id', $orderId)
            ->where('processing_branch_id', $labId)
            ->whereNotNull('test_id')
            ->whereNotIn('status', self::NOT_REPORTED)
            ->orderBy('id')
            ->pluck('id')
            ->all());
    }

    /**
     * The lab has the sample and is working on these tests (spec §7.5
     * order_items: collected → processing).
     *
     * @param  list<string>  $orderItemIds
     */
    public function markItemsProcessing(string $organizationId, array $orderItemIds): void
    {
        $this->inOrganization($organizationId, fn () => OrderItem::query()
            ->whereKey($orderItemIds)
            ->whereIn('status', [OrderItemStatus::Ordered, OrderItemStatus::Collected])
            ->update(['status' => OrderItemStatus::Processing]));
    }

    /**
     * A released report covers these tests. The order is completed once every
     * test line is reported, and partially reported until then (spec §5.2).
     *
     * @param  list<string>  $orderItemIds
     */
    public function markItemsReported(string $organizationId, string $orderId, array $orderItemIds): void
    {
        $this->inOrganization($organizationId, function () use ($orderId, $orderItemIds): void {
            DB::transaction(function () use ($orderId, $orderItemIds): void {
                $order = Order::query()->lockForUpdate()->findOrFail($orderId);

                OrderItem::query()
                    ->where('order_id', $order->id)
                    ->whereKey($orderItemIds)
                    ->whereNotIn('status', self::NOT_REPORTED)
                    ->update(['status' => OrderItemStatus::Reported]);

                $outstanding = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->whereNotNull('test_id')
                    ->whereNotIn('status', [...self::NOT_REPORTED, OrderItemStatus::Reported])
                    ->exists();
                $target = $outstanding ? OrderStatus::PartiallyReported : OrderStatus::Completed;

                if ($order->status !== $target && $this->orderStates->canTransition($order->status, $target)) {
                    $this->orderStates->transition($order, $target);
                }
            });
        });
    }

    /**
     * The referring doctor, reached the way they asked to receive reports;
     * null when they asked for none or there is no doctor.
     */
    public function doctorRecipient(string $organizationId, string $orderId): ?NotificationRecipient
    {
        return $this->inOrganization($organizationId, function () use ($organizationId, $orderId): ?NotificationRecipient {
            $doctorId = Order::query()->whereKey($orderId)->value('doctor_id');
            $doctor = $doctorId === null ? null : Doctor::query()->find($doctorId);

            if ($doctor === null || $doctor->report_delivery === ReportDelivery::None) {
                return null;
            }

            return new NotificationRecipient(
                'doctor',
                $doctor->id,
                $organizationId,
                $doctor->report_delivery === ReportDelivery::Email ? null : $doctor->phone,
                $doctor->email,
                $doctor->report_delivery === ReportDelivery::Whatsapp,
            );
        });
    }

    /** Whether the client has an invoice past its due date with money still owed. */
    public function b2bClientHasOverdueInvoices(string $organizationId, string $b2bClientId): bool
    {
        return $this->inOrganization($organizationId, fn (): bool => Invoice::query()
            ->where('b2b_client_id', $b2bClientId)
            ->whereDate('due_date', '<', CarbonImmutable::today())
            ->whereColumn('amount_paid', '<', 'total')
            ->exists());
    }

    private function labItem(OrderItem $item): LabOrderItem
    {
        return new LabOrderItem(
            $item->id,
            $item->order_id,
            (string) $item->test_id,
            $item->processing_branch_id,
            $item->due_at,
            ! in_array($item->status, self::NOT_REPORTED, true),
        );
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
