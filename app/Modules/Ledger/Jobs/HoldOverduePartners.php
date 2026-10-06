<?php

namespace App\Modules\Ledger\Jobs;

use App\Modules\Ledger\Domain\OverdueSince;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Network\Services\PartnerAccount;
use App\Modules\Network\Services\PartnerAccounts;
use App\Modules\Shared\Jobs\WithSystemScope;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Franchise auto-hold (spec §9, daily, off by default): a partner that has
 * been past its credit limit for longer than the grace days stops taking new
 * orders (franchise suspended, B2B client on hold) until HQ Finance clears it.
 */
final class HoldOverduePartners implements ShouldQueue
{
    use Queueable;

    /** Rows read per partner; a partner past its limit for longer than this many rows is overdue anyway. */
    private const ROWS_CHECKED = 500;

    public function handle(NetworkDirectory $network, PartnerAccounts $accounts): void
    {
        if (! (bool) config('pathology.ledger.auto_hold.enabled')) {
            return;
        }

        $graceStart = CarbonImmutable::now()->subDays((int) config('pathology.ledger.auto_hold.grace_days'));

        foreach ($network->organizationIds() as $organizationId) {
            WithSystemScope::run($organizationId, function () use ($organizationId, $accounts, $graceStart): void {
                foreach ($accounts->settlingPartners($organizationId) as $account) {
                    $since = $account->canTrade ? OverdueSince::find($this->newestRows($account), $account->creditLimit) : null;

                    if ($since !== null && $since->isBefore($graceStart)) {
                        $accounts->holdForOverdueDues($organizationId, $account->type, $account->id);
                    }
                }
            });
        }
    }

    /** @return list<array{balance_after: Money, created_at: CarbonImmutable}> */
    private function newestRows(PartnerAccount $account): array
    {
        return LedgerEntry::query()
            ->where($account->type === PartnerType::Franchise ? 'franchise_id' : 'b2b_client_id', $account->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::ROWS_CHECKED)
            ->get(['balance_after', 'created_at'])
            ->map(fn (LedgerEntry $row) => ['balance_after' => $row->balance_after, 'created_at' => $row->created_at])
            ->all();
    }
}
