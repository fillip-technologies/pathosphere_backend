<?php

namespace App\Modules\Ledger\Jobs;

use App\Modules\Ledger\Domain\PartnerModel;
use App\Modules\Ledger\Domain\WalletSufficiency;
use App\Modules\Ledger\Services\PartnerModels;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Network\Services\PartnerAccounts;
use App\Modules\Shared\Jobs\WithSystemScope;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Wallet low-balance alert (spec §9, hourly): a trading wholesale franchise
 * whose available money (balance + credit limit) is below the threshold, or
 * that uses 80% or more of its credit limit, is told once a day.
 */
final class AlertLowWalletBalances implements ShouldQueue
{
    use Queueable;

    public function handle(NetworkDirectory $network, PartnerAccounts $accounts, NotificationService $notifications): void
    {
        $threshold = Money::fromString((string) config('pathology.ledger.low_balance_threshold'));
        $warnPercent = (int) config('pathology.ledger.credit_warning_percent');
        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();

        foreach ($network->organizationIds() as $organizationId) {
            WithSystemScope::run($organizationId, function () use ($organizationId, $accounts, $notifications, $threshold, $warnPercent, $today): void {
                foreach ($accounts->settlingPartners($organizationId) as $account) {
                    if (! $account->canTrade || PartnerModels::of($account) !== PartnerModel::Wholesale) {
                        continue;
                    }

                    $available = WalletSufficiency::available($account->balance, $account->creditLimit);
                    $low = $available->isLessThan($threshold)
                        || WalletSufficiency::creditUsedPercent($account->balance, $account->creditLimit) >= $warnPercent;

                    if (! $low || ! Cache::add("wallet-low:{$account->id}:{$today}", true, now()->addDay())) {
                        continue;
                    }

                    $notifications->notify(
                        'wallet_low_balance',
                        new NotificationRecipient($account->type->value, $account->id, $organizationId, $account->phone, $account->email, false),
                        ['partner_name' => $account->name, 'balance' => (string) $account->balance, 'available' => (string) $available],
                    );
                }
            });
        }
    }
}
