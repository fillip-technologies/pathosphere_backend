<?php

namespace App\Modules\Booking\Errors;

use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Money\Money;

/** Booking and billing failures with stable codes. */
final class BookingError
{
    public static function patientMerged(string $survivingPatientId): DomainError
    {
        return new DomainError('PATIENT_MERGED', 'This patient record was merged. Book on the surviving record.', 422, [
            ['field' => 'patient_id', 'merged_into_id' => $survivingPatientId],
        ]);
    }

    public static function cannotMerge(string $reason): DomainError
    {
        return new DomainError('PATIENT_MERGE_NOT_ALLOWED', $reason, 422);
    }

    public static function franchiseSuspended(): DomainError
    {
        return new DomainError('FRANCHISE_SUSPENDED', 'This franchise is not active, so its branches cannot take new orders.', 422);
    }

    public static function b2bClientOnHold(): DomainError
    {
        return new DomainError('B2B_CLIENT_ON_HOLD', 'This B2B client is on hold or closed, so it cannot place new orders.', 422, [['field' => 'b2b_client_id']]);
    }

    public static function b2bDiscountNotAllowed(): DomainError
    {
        return new DomainError('B2B_DISCOUNT_NOT_ALLOWED', 'B2B clients are billed at their own rate list; change the list instead of discounting.', 422, [['field' => 'discount.amount']]);
    }

    public static function discountTooLarge(): DomainError
    {
        return new DomainError('DISCOUNT_TOO_LARGE', 'The discount cannot exceed the bill amount.', 422, [['field' => 'discount.amount']]);
    }

    public static function discountNeedsApproval(string $limitPercent): DomainError
    {
        return new DomainError(
            'DISCOUNT_NEEDS_APPROVAL',
            "Discounts above {$limitPercent}% need someone with discount approval (e.g. the branch admin).",
            403,
            [['field' => 'discount.amount']],
        );
    }

    public static function overpayment(Money $balanceDue): DomainError
    {
        return new DomainError('PAYMENT_EXCEEDS_BALANCE', "The payment is more than the balance due ({$balanceDue}).", 422, [['field' => 'amount']]);
    }

    public static function invoiceNotPayable(): DomainError
    {
        return new DomainError('INVOICE_NOT_PAYABLE', 'This invoice does not take payments (B2B credit, already paid or refunded).', 422);
    }

    public static function refundExceedsPayment(Money $refundable): DomainError
    {
        return new DomainError('REFUND_EXCEEDS_PAYMENT', "At most {$refundable} can still be refunded on this payment.", 422, [['field' => 'amount']]);
    }

    public static function refundNeedsApproval(): DomainError
    {
        return new DomainError('REFUND_APPROVAL_REQUIRED', 'This order has payments. Cancelling it refunds them, which needs refund approval.', 403);
    }

    public static function orderNotOpen(): DomainError
    {
        return new DomainError('ORDER_NOT_OPEN', 'Tests can only be added to a confirmed order that is not finished.', 422);
    }

    public static function phlebotomistNotAvailable(): DomainError
    {
        return new DomainError('PHLEBOTOMIST_NOT_AVAILABLE', 'Choose an active phlebotomist from this branch.', 422, [['field' => 'phlebotomist_id']]);
    }

    public static function gpsRequired(): DomainError
    {
        return new DomainError('GPS_REQUIRED', 'Collection needs the phone location as proof of visit.', 422, [['field' => 'latitude']]);
    }

    public static function invalidWebhookSignature(): DomainError
    {
        return new DomainError('INVALID_SIGNATURE', 'The webhook signature is not valid.', 400);
    }
}
