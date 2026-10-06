<?php

namespace App\Modules\Network\Services;

use App\Modules\Network\Enums\BillingModel;
use App\Modules\Network\Enums\SettlementCycle;
use Carbon\CarbonImmutable;

/** The money terms of a franchise's agreement. */
final class BillingTerms
{
    public function __construct(
        public readonly string $agreementId,
        public readonly string $agreementNo,
        public readonly BillingModel $billingModel,
        /** Set for revenue share, e.g. "30.00". */
        public readonly ?string $commissionPct,
        public readonly SettlementCycle $settlementCycle,
        public readonly CarbonImmutable $startDate,
        /** False when the agreement has expired or was terminated. */
        public readonly bool $inForce,
    ) {}
}
