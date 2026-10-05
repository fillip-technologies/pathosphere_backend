<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Enums\OrderSource;
use App\Modules\Catalogue\Domain\QuoteRequestItem;
use App\Modules\Shared\Money\Money;

/** Everything needed to book one order. Built and checked by BookOrderRequest. */
final class BookOrderCommand
{
    /** @param  list<QuoteRequestItem>  $items */
    public function __construct(
        public readonly string $patientId,
        public readonly string $branchId,
        public readonly ?string $doctorId,
        public readonly ?string $b2bClientId,
        public readonly OrderSource $source,
        public readonly ?string $externalRef,
        public readonly ?string $clinicalNotes,
        public readonly array $items,
        public readonly Money $discount,
        public readonly ?string $discountReason,
        public readonly ?DeskPayment $payment,
        public readonly ?HomeVisitRequest $homeCollection,
    ) {}
}
