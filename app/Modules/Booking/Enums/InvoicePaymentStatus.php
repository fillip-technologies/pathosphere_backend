<?php

namespace App\Modules\Booking\Enums;

/** credit: billed to a B2B client on credit terms. */
enum InvoicePaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Credit = 'credit';
}
