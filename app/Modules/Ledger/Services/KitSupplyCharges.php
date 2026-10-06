<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Domain\PostingRules;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Shared\Money\Money;

/**
 * Kits and consumables HQ supplies to a franchise branch are debited to the
 * franchise when the branch receives them (spec §7.7, §7.8 kit supply). Called
 * inside the receipt transaction; posting twice for one transfer is a no-op.
 */
final class KitSupplyCharges
{
    public function __construct(private readonly LedgerPostingService $postings) {}

    public function chargeReceived(string $organizationId, string $franchiseId, string $transferId, string $transferNo, Money $charge): void
    {
        $posting = PostingRules::kitSupplyReceived($transferId, $transferNo, $charge);

        if ($posting !== null) {
            $this->postings->post($organizationId, PartnerType::Franchise, $franchiseId, [$posting]);
        }
    }
}
