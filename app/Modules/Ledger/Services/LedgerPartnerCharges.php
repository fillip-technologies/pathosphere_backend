<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Booking\Contracts\PartnerCharge;
use App\Modules\Booking\Contracts\PartnerChargePolicy;
use App\Modules\Ledger\Domain\ChargedItem;
use App\Modules\Ledger\Domain\PartnerModel;
use App\Modules\Ledger\Domain\PostingRules;
use App\Modules\Ledger\Domain\ReferenceType;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Errors\LedgerError;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Services\PartnerAccounts;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;

/**
 * Partner charges for orders (spec §5.2, §7.8), called by booking inside its
 * transaction. A wholesale franchise pays each test's partner price from its
 * wallet (WALLET_INSUFFICIENT when balance + credit limit is short); a B2B
 * client is charged its client price on credit; a revenue-share franchise is
 * charged at settlement; company branches never touch the ledger.
 */
final class LedgerPartnerCharges implements PartnerChargePolicy
{
    public function __construct(
        private readonly PartnerAccounts $accounts,
        private readonly LedgerPostingService $postings,
        private readonly CurrentScope $currentScope,
    ) {}

    public function chargeForConfirmedOrder(PartnerCharge $charge): void
    {
        if ($charge->isCompanyOrder()) {
            return;
        }

        [$partnerType, $partnerId] = $this->partyOf($charge);
        $account = $this->accounts->find($charge->organizationId, $partnerType, $partnerId)
            ?? throw new \LogicException("Order {$charge->orderId} names a partner that does not exist.");
        $model = PartnerModels::of($account);

        if ($account->isFranchise() && ($model === null || ! $account->terms?->inForce)) {
            throw LedgerError::agreementNotInForce();
        }

        /** @var PartnerModel $model */
        $this->postings->post(
            $charge->organizationId,
            $partnerType,
            $partnerId,
            PostingRules::orderConfirmed($model, $charge->orderNo, $charge->partnerPriceByOrderItem),
            enforceWallet: $model === PartnerModel::Wholesale,
        );
    }

    public function reverseForCancelledOrder(PartnerCharge $charge): void
    {
        if ($charge->isCompanyOrder()) {
            return;
        }

        $this->reverse($charge->organizationId, array_keys($charge->partnerPriceByOrderItem), "Order {$charge->orderNo} cancelled");
    }

    /**
     * Credits back every charge posted for these order items that has not
     * been reversed yet. Items never charged (revenue share, free lines) are
     * skipped.
     *
     * @param  list<string>  $orderItemIds
     */
    public function reverse(string $organizationId, array $orderItemIds, string $reason): void
    {
        $charges = $this->currentScope->runAs(ScopeContext::system($organizationId), fn () => LedgerEntry::query()
            ->where('entry_type', LedgerEntryType::PartnerCharge)
            ->where('reference_type', ReferenceType::ORDER_ITEM)
            ->whereIn('reference_id', $orderItemIds)
            ->get());

        foreach ($charges->groupBy(fn (LedgerEntry $entry) => $entry->franchise_id ?? $entry->b2b_client_id) as $partnerCharges) {
            /** @var LedgerEntry $first */
            $first = $partnerCharges->first();
            $chargedItems = $partnerCharges->map(fn (LedgerEntry $entry) => new ChargedItem((string) $entry->reference_id, $entry->debit))->values()->all();

            $this->postings->post(
                $organizationId,
                $first->franchise_id !== null ? PartnerType::Franchise : PartnerType::B2bClient,
                (string) ($first->franchise_id ?? $first->b2b_client_id),
                PostingRules::chargesReversed($chargedItems, $reason),
            );
        }
    }

    /** @return array{PartnerType, string} */
    private function partyOf(PartnerCharge $charge): array
    {
        // A B2B order at a franchise lab bills the client, not the franchise.
        return $charge->b2bClientId !== null
            ? [PartnerType::B2bClient, $charge->b2bClientId]
            : [PartnerType::Franchise, (string) $charge->franchiseId];
    }
}
