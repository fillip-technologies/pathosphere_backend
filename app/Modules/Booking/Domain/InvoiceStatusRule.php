<?php

namespace App\Modules\Booking\Domain;

use App\Modules\Booking\Enums\InvoicePaymentStatus;
use App\Modules\Shared\Money\Money;

/**
 * An invoice's payment status follows from its amounts (spec §5.2), so it is
 * derived, never typed in.
 */
final class InvoiceStatusRule
{
    public static function statusFor(Money $total, Money $netPaid, bool $anyRefund, bool $billedOnCredit): InvoicePaymentStatus
    {
        if ($billedOnCredit && $netPaid->isZero()) {
            return InvoicePaymentStatus::Credit;
        }

        return match (true) {
            ! $netPaid->isLessThan($total) => InvoicePaymentStatus::Paid,
            $anyRefund && $netPaid->isZero() => InvoicePaymentStatus::Refunded,
            $anyRefund => InvoicePaymentStatus::PartiallyRefunded,
            $netPaid->isZero() => InvoicePaymentStatus::Unpaid,
            default => InvoicePaymentStatus::PartiallyPaid,
        };
    }
}
