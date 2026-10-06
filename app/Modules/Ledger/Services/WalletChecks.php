<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Domain\PartnerModel;
use App\Modules\Ledger\Domain\WalletSufficiency;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Services\PartnerAccounts;
use App\Modules\Shared\Money\Money;

/**
 * The wallet check on POST /order-quotes (spec §8): only bookings that will
 * be debited from a prepaid wallet get one. Nothing is reserved; the debit
 * at confirmation checks again under the row lock.
 */
final class WalletChecks
{
    public function __construct(private readonly PartnerAccounts $accounts) {}

    public function forBooking(string $organizationId, ?string $franchiseId, ?string $b2bClientId, Money $partnerTotal): ?WalletCheck
    {
        if ($franchiseId === null || $b2bClientId !== null) {
            return null;
        }

        $account = $this->accounts->find($organizationId, PartnerType::Franchise, $franchiseId);

        if ($account === null || PartnerModels::of($account) !== PartnerModel::Wholesale) {
            return null;
        }

        return new WalletCheck(
            $account->balance,
            $account->creditLimit,
            WalletSufficiency::available($account->balance, $account->creditLimit),
            $partnerTotal,
            WalletSufficiency::covers($account->balance, $account->creditLimit, $partnerTotal),
            WalletSufficiency::creditUsedPercent($account->balance, $account->creditLimit),
        );
    }
}
