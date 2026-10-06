<?php

namespace App\Modules\Ledger\Listeners;

use App\Modules\Ledger\Domain\PostingRules;
use App\Modules\Ledger\Services\LedgerPostingService;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Events\AgreementSigned;

/** Agreement signed: the franchise fee is owed, the deposit is held (spec §5.1 step 6, §7.8). */
final class PostAgreementCharges
{
    public function __construct(private readonly LedgerPostingService $postings) {}

    public function handle(AgreementSigned $event): void
    {
        $this->postings->post(
            $event->organizationId,
            PartnerType::Franchise,
            $event->franchiseId,
            PostingRules::agreementSigned($event->agreementId, $event->agreementNo, $event->franchiseFee, $event->securityDeposit),
        );
    }
}
