<?php

namespace App\Modules\Booking\Services;

use Carbon\CarbonImmutable;

/** An ordered test as the lab that runs it sees it. */
final class LabOrderItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $orderId,
        public readonly string $testId,
        public readonly ?string $processingBranchId,
        public readonly ?CarbonImmutable $dueAt,
        /** Cancelled lines and lines replaced by a redraw are never reported. */
        public readonly bool $isReportable,
    ) {}
}
