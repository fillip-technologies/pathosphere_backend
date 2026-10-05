<?php

namespace App\Modules\Booking\Listeners;

use App\Modules\Booking\Events\OrderConfirmed;
use App\Modules\Booking\Models\Invoice;
use App\Modules\Booking\Models\Order;
use App\Modules\Shared\Jobs\WithSystemScope;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Booking SMS / WhatsApp / email to the patient (spec §9 OrderConfirmed). */
final class SendBookingConfirmation implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(OrderConfirmed $event): void
    {
        WithSystemScope::run($event->organizationId, function () use ($event): void {
            $order = Order::query()->with(['patient', 'items'])->findOrFail($event->orderId);
            $patient = $order->patient;
            $total = Invoice::query()->where('order_id', $order->id)->get()
                ->reduce(fn (Money $sum, Invoice $invoice) => $sum->add($invoice->total), Money::zero());

            $this->notifications->notify(
                'booking_confirmed',
                new NotificationRecipient('patient', $patient->id, $order->organization_id, $patient->phone, $patient->email, $patient->whatsapp_opted_in_at !== null),
                [
                    'patient_name' => $patient->name,
                    'order_no' => $order->order_no,
                    'test_count' => (string) $order->items->whereNull('parent_item_id')->count(),
                    'total' => (string) $total,
                ],
            );
        });
    }
}
