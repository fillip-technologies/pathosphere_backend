<?php

namespace App\Modules\Booking\Services;

use App\Modules\Auth\Permissions\Permission;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Domain\DiscountAllocator;
use App\Modules\Booking\Domain\OrderLineDraft;
use App\Modules\Booking\Domain\OrderLinePlanner;
use App\Modules\Booking\Enums\HomeCollectionStatus;
use App\Modules\Booking\Enums\OrderItemStatus;
use App\Modules\Booking\Enums\OrderStatus;
use App\Modules\Booking\Errors\BookingError;
use App\Modules\Booking\Models\Doctor;
use App\Modules\Booking\Models\HomeCollection;
use App\Modules\Booking\Models\Invoice;
use App\Modules\Booking\Models\Order;
use App\Modules\Booking\Models\OrderItem;
use App\Modules\Booking\Models\Patient;
use App\Modules\Booking\Models\Payment;
use App\Modules\Booking\StateMachines\HomeCollectionStateMachine;
use App\Modules\Booking\StateMachines\OrderStateMachine;
use App\Modules\Catalogue\Domain\Quote;
use App\Modules\Catalogue\Domain\QuoteLine;
use App\Modules\Catalogue\Domain\QuoteProblem;
use App\Modules\Catalogue\Domain\QuoteRequestItem;
use App\Modules\Catalogue\Errors\CatalogueError;
use App\Modules\Catalogue\Services\OrderQuoteService;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Numbering\NumberSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books orders (spec §5.2, §8 "create order"). In one transaction: price and
 * route through the same quote service as POST /order-quotes, expand
 * packages, create order, items, invoice and payment, confirm when the
 * payment rule is met (which posts the partner charge), then notify after
 * commit.
 */
final class OrderBookingService
{
    private const DEFAULT_ORDER_FORMAT = '{branch_code}-{seq:7}';

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NetworkDirectory $network,
        private readonly NumberSequenceService $sequences,
        private readonly OrderQuoteService $quotes,
        private readonly BillingService $billing,
        private readonly HomeCollectionService $homeCollections,
        private readonly OrderStateMachine $orderStates,
        private readonly HomeCollectionStateMachine $homeCollectionStates,
    ) {}

    public function book(StaffContext $staff, BookOrderCommand $command): Order
    {
        $patient = $this->bookablePatient($command->patientId);
        $this->assertDoctorExists($command->doctorId);

        $priced = $this->quotes->quote($command->branchId, $command->b2bClientId, $command->items);
        $quote = $this->bookableQuote($priced['quote']);
        $branch = $priced['branch'];

        if (! $this->network->franchiseAllowsBooking($branch->franchiseId)) {
            throw BookingError::franchiseSuspended();
        }

        $this->assertDiscountAllowed($staff, $command->discount, $quote->mrpTotal(), $branch->organizationId);
        $collectionCharge = $command->homeCollection->collectionCharge ?? Money::zero();

        return DB::transaction(function () use ($staff, $command, $patient, $quote, $branch, $collectionCharge): Order {
            $bookedAt = CarbonImmutable::now();

            $order = new Order([
                'patient_id' => $patient->id,
                'branch_id' => $branch->branchId,
                'franchise_id' => $branch->franchiseId,
                'b2b_client_id' => $command->b2bClientId,
                'doctor_id' => $command->doctorId,
                'order_source' => $command->source,
                'external_ref' => $command->externalRef,
                'clinical_notes' => $command->clinicalNotes,
                'order_date' => $bookedAt,
                'status' => OrderStatus::Draft,
            ]);
            $order->organization_id = $branch->organizationId;
            $order->order_no = $this->orderNumber($branch->organizationId, $branch->branchId);
            $order->save();
            $this->auditLogger->recordCreated('order.create', $order);

            $this->saveLines($order, OrderLinePlanner::plan($quote, $command->discount, $bookedAt), $command->discountReason);

            if ($command->homeCollection !== null) {
                $this->homeCollections->createForOrder($order, $command->homeCollection);
            }

            $invoice = $this->billing->createInvoice($order, $quote->mrpTotal()->add($collectionCharge), $command->discount);

            if ($command->payment !== null) {
                $this->billing->recordDeskPayment($staff, $invoice, $command->payment->mode, $command->payment->amount, $command->payment->transactionId);
            } else {
                $this->billing->refresh($invoice);
            }

            return $order->refresh();
        });
    }

    /**
     * Add-on tests on an open order get their own supplementary invoice
     * (spec §8 POST /orders/{id}/items).
     *
     * @param  list<QuoteRequestItem>  $items
     */
    public function addItems(StaffContext $staff, Order $order, array $items, Money $discount, ?string $discountReason, ?DeskPayment $payment): Invoice
    {
        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::InProgress, OrderStatus::PartiallyReported], true)) {
            throw BookingError::orderNotOpen();
        }

        $quote = $this->bookableQuote($this->quotes->quote($order->branch_id, $order->b2b_client_id, $items)['quote']);
        $this->assertNotAlreadyOrdered($order, $quote);
        $this->assertDiscountAllowed($staff, $discount, $quote->mrpTotal(), $order->organization_id);

        return DB::transaction(function () use ($staff, $order, $quote, $discount, $discountReason, $payment): Invoice {
            $this->saveLines($order, OrderLinePlanner::plan($quote, $discount, CarbonImmutable::now()), $discountReason);
            $invoice = $this->billing->createInvoice($order, $quote->mrpTotal(), $discount);

            if ($payment !== null) {
                $this->billing->recordDeskPayment($staff, $invoice, $payment->mode, $payment->amount, $payment->transactionId);
            }

            $this->auditLogger->record('order.items_add', $order, [], ['invoice_id' => $invoice->id]);

            // Payments update a locked copy of the invoice; answer with the stored state.
            return $invoice->refresh();
        });
    }

    /**
     * Cancels an order before collection (spec §5.2): items and home visit
     * are cancelled, payments refunded, and the partner charge reversed.
     */
    public function cancel(StaffContext $staff, Order $order, string $reason): Order
    {
        $payments = Payment::query()
            ->with('refunds')
            ->whereIn('invoice_id', Invoice::query()->where('order_id', $order->id)->select('id'))
            ->get()
            ->filter(fn (Payment $payment) => ! $payment->refundableAmount()->isZero());

        if ($payments->isNotEmpty() && ! $staff->has(Permission::ApproveRefund)) {
            throw BookingError::refundNeedsApproval();
        }

        return DB::transaction(function () use ($staff, $order, $reason, $payments): Order {
            $this->orderStates->transition($order, OrderStatus::Cancelled, ['cancelled_reason' => $reason]);
            $order->items()->update(['status' => OrderItemStatus::Cancelled]);

            $visit = HomeCollection::query()->where('order_id', $order->id)->first();
            if ($visit !== null && $this->homeCollectionStates->canTransition($visit->status, HomeCollectionStatus::Cancelled)) {
                $this->homeCollectionStates->transition($visit, HomeCollectionStatus::Cancelled, ['status_note' => $reason]);
            }

            foreach ($payments as $payment) {
                $this->billing->refund($staff, $payment, $payment->refundableAmount(), 'Order cancelled');
            }

            return $order;
        });
    }

    /** @param  list<OrderLineDraft>  $lines */
    private function saveLines(Order $order, array $lines, ?string $discountReason): void
    {
        foreach ($lines as $line) {
            $parent = $this->saveLine($order, $line, null, $discountReason);

            foreach ($line->children as $child) {
                $this->saveLine($order, $child, $parent->id, null);
            }
        }
    }

    private function saveLine(Order $order, OrderLineDraft $line, ?string $parentItemId, ?string $discountReason): OrderItem
    {
        $item = new OrderItem([
            'test_id' => $line->testId,
            'package_id' => $line->packageId,
            'parent_item_id' => $parentItemId,
            'processing_branch_id' => $line->processingBranchId,
            'mrp_price' => $line->mrpPrice,
            'partner_price' => $line->partnerPrice,
            'discount' => $line->discount,
            'discount_reason' => $line->discount->isZero() ? null : $discountReason,
            'net_price' => $line->netPrice(),
            'due_at' => $line->dueAt,
            'status' => OrderItemStatus::Ordered,
        ]);
        $item->order_id = $order->id;
        $item->save();

        return $item;
    }

    private function bookablePatient(string $patientId): Patient
    {
        $patient = Patient::query()->find($patientId)
            ?? throw ValidationException::withMessages(['patient_id' => 'The selected patient does not exist.']);

        if ($patient->merged_into_id !== null) {
            throw BookingError::patientMerged($patient->merged_into_id);
        }

        return $patient;
    }

    private function assertDoctorExists(?string $doctorId): void
    {
        if ($doctorId !== null && ! Doctor::query()->whereKey($doctorId)->exists()) {
            throw ValidationException::withMessages(['doctor_id' => 'The selected doctor does not exist.']);
        }
    }

    private function bookableQuote(Quote $quote): Quote
    {
        return $quote->isBookable() ? $quote : throw CatalogueError::unbookable($quote);
    }

    private function assertNotAlreadyOrdered(Order $order, Quote $quote): void
    {
        $ordered = $order->items()->where('status', '!=', OrderItemStatus::Cancelled)->whereNotNull('test_id')->pluck('test_id')->all();
        $problems = [];

        foreach ($quote->lines as $index => $line) {
            $testIds = $line->lineType === QuoteLine::PACKAGE ? array_map(fn (QuoteLine $child) => $child->itemId, $line->children) : [$line->itemId];

            foreach (array_intersect($testIds, $ordered) as $testId) {
                $problems[] = new QuoteProblem(QuoteProblem::DUPLICATE_TEST, 'This test is already on the order.', $index, $testId);
            }
        }

        if ($problems !== []) {
            throw CatalogueError::unbookable(new Quote([], $problems));
        }
    }

    private function assertDiscountAllowed(StaffContext $staff, Money $discount, Money $gross, string $organizationId): void
    {
        if ($discount->isZero()) {
            return;
        }

        if ($discount->isGreaterThan($gross)) {
            throw BookingError::discountTooLarge();
        }

        $limit = (string) ($this->network->organizationSettings($organizationId)['discount_approval_percent']
            ?? config('pathology.booking.discount_approval_percent'));

        if (DiscountAllocator::needsApproval($discount, $gross, $limit) && ! $staff->has(Permission::ApproveDiscount)) {
            throw BookingError::discountNeedsApproval($limit);
        }
    }

    private function orderNumber(string $organizationId, string $branchId): string
    {
        $format = (string) ($this->network->organizationSettings($organizationId)['order_number_format'] ?? self::DEFAULT_ORDER_FORMAT);

        return $this->sequences->nextFormatted($organizationId, "order:{$branchId}", $format, ['branch_code' => $this->network->branchCode($branchId)]);
    }
}
