<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Contracts\PaymentGateway;
use App\Modules\Booking\Contracts\PaymentLink;
use App\Modules\Booking\Contracts\PaymentLinkRequest;
use App\Modules\Booking\Enums\InvoicePaymentStatus;
use App\Modules\Booking\Errors\BookingError;
use App\Modules\Booking\Models\Invoice;
use App\Modules\Booking\Models\Patient;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;
use Carbon\CarbonImmutable;

/**
 * Online payment links for an invoice's balance (POST /payment-links). The
 * payment itself is recorded only when the gateway webhook confirms it.
 */
final class PaymentLinkService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function createAndSend(Invoice $invoice): PaymentLink
    {
        if (! in_array($invoice->payment_status, [InvoicePaymentStatus::Unpaid, InvoicePaymentStatus::PartiallyPaid], true)) {
            throw BookingError::invoiceNotPayable();
        }

        $patient = Patient::query()->findOrFail($invoice->order->patient_id);
        $link = $this->gateway->createPaymentLink(new PaymentLinkRequest(
            $invoice->id,
            $invoice->invoice_no,
            $invoice->balanceDue(),
            $patient->name,
            $patient->phone,
            $patient->email,
            CarbonImmutable::now()->addHours((int) config('pathology.payments.link_expiry_hours')),
        ));

        $this->auditLogger->record('invoice.payment_link', $invoice, [], ['link_id' => $link->linkId, 'amount' => (string) $invoice->balanceDue()]);

        $this->notifications->notify(
            'payment_link',
            new NotificationRecipient('patient', $patient->id, $patient->organization_id, $patient->phone, $patient->email, $patient->whatsapp_opted_in_at !== null),
            [
                'patient_name' => $patient->name,
                'invoice_no' => $invoice->invoice_no,
                'amount' => (string) $invoice->balanceDue(),
                'payment_url' => $link->url,
            ],
        );

        return $link;
    }
}
