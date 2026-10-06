<?php

namespace App\Modules\Network\Events;

use App\Modules\Shared\Money\Money;

/**
 * An agreement came back signed and is now active (spec §9). Dispatched
 * inside the activation transaction, so the franchise fee and deposit are
 * posted to the ledger together with the activation or not at all.
 */
final class AgreementSigned
{
    public function __construct(
        public readonly string $agreementId,
        public readonly string $agreementNo,
        public readonly string $franchiseId,
        public readonly string $organizationId,
        public readonly Money $franchiseFee,
        public readonly Money $securityDeposit,
    ) {}
}
