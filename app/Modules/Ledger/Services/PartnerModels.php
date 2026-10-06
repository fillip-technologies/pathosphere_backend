<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Domain\PartnerModel;
use App\Modules\Network\Enums\BillingModel;
use App\Modules\Network\Services\PartnerAccount;

/** Which column of the posting table (spec §7.8) a partner account follows. */
final class PartnerModels
{
    /** Null for a franchise that never had an agreement. */
    public static function of(PartnerAccount $account): ?PartnerModel
    {
        if (! $account->isFranchise()) {
            return PartnerModel::B2bClient;
        }

        return match ($account->terms?->billingModel) {
            BillingModel::Wholesale => PartnerModel::Wholesale,
            BillingModel::RevenueShare => PartnerModel::RevenueShare,
            null => null,
        };
    }
}
