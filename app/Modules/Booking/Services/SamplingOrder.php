<?php

namespace App\Modules\Booking\Services;

/** What sample collection needs to know about an order. */
final class SamplingOrder
{
    /** @param  list<SamplingOrderLine>  $linesAwaitingCollection  test lines still at `ordered` */
    public function __construct(
        public readonly string $id,
        public readonly string $organizationId,
        public readonly string $branchId,
        /** Confirmed and not finished or cancelled: containers may be drawn. */
        public readonly bool $isOpenForSampling,
        public readonly array $linesAwaitingCollection,
    ) {}
}
