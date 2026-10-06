<?php

namespace App\Modules\Ledger\Domain;

/** The three ways a partner's money is posted (spec §7.8 posting table columns). */
enum PartnerModel: string
{
    case Wholesale = 'wholesale';
    case RevenueShare = 'revenue_share';
    case B2bClient = 'b2b_client';

    /** Prepaid wallets and B2B credit carry a positive balance forward instead of paying it out. */
    public function paysOutCredit(): bool
    {
        return $this === self::RevenueShare;
    }
}
