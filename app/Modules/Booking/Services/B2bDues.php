<?php

namespace App\Modules\Booking\Services;

use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/** What a B2B client owes on invoices past their due date. */
final class B2bDues
{
    public function __construct(
        public readonly string $b2bClientId,
        public readonly int $invoiceCount,
        public readonly Money $amountDue,
        public readonly CarbonImmutable $oldestDueDate,
    ) {}
}
