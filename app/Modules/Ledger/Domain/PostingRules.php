<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SettlementDirection;
use App\Modules\Shared\Money\Money;

/**
 * The posting table of spec §7.8, as pure decisions: given what happened and
 * the partner's model, which ledger rows to write. Every row carries an
 * idempotency key, so the same event can never post twice.
 */
final class PostingRules
{
    /**
     * Order confirmed: wholesale franchises pay the partner price and B2B
     * clients their client price, one row per priced item; revenue-share
     * franchises are charged at settlement instead.
     *
     * @param  array<string, Money>  $priceByOrderItem
     * @return list<Posting>
     */
    public static function orderConfirmed(PartnerModel $model, string $orderNo, array $priceByOrderItem): array
    {
        if ($model === PartnerModel::RevenueShare) {
            return [];
        }

        $postings = [];
        foreach ($priceByOrderItem as $orderItemId => $price) {
            // Package children and free recollections cost nothing.
            if ($price->isZero()) {
                continue;
            }

            $postings[] = Posting::debit(
                LedgerEntryType::PartnerCharge,
                $price,
                "Test charge, order {$orderNo}",
                ReferenceType::ORDER_ITEM,
                $orderItemId,
                "charge:{$orderItemId}",
            );
        }

        return $postings;
    }

    /**
     * Cancelled before collection, or a rejected sample when the policy says
     * so: each charged item is credited back once.
     *
     * @param  list<ChargedItem>  $chargedItems
     * @return list<Posting>
     */
    public static function chargesReversed(array $chargedItems, string $reason): array
    {
        return array_map(fn (ChargedItem $item) => Posting::credit(
            LedgerEntryType::RefundReversal,
            $item->amount,
            $reason,
            ReferenceType::ORDER_ITEM,
            $item->orderItemId,
            "reversal:{$item->orderItemId}",
        ), $chargedItems);
    }

    /**
     * Agreement signed: the one-time fee is owed to HQ, the refundable
     * deposit is held for the franchise.
     *
     * @return list<Posting>
     */
    public static function agreementSigned(string $agreementId, string $agreementNo, Money $fee, Money $deposit): array
    {
        $postings = [];

        if (! $fee->isZero()) {
            $postings[] = Posting::debit(LedgerEntryType::FranchiseFee, $fee, "Franchise fee, agreement {$agreementNo}", ReferenceType::AGREEMENT, $agreementId, "fee:{$agreementId}");
        }

        if (! $deposit->isZero()) {
            $postings[] = Posting::credit(LedgerEntryType::SecurityDeposit, $deposit, "Security deposit, agreement {$agreementNo}", ReferenceType::AGREEMENT, $agreementId, "deposit:{$agreementId}");
        }

        return $postings;
    }

    /** Kits received from HQ are charged to franchises of both models; B2B clients never receive stock. */
    public static function kitSupplyReceived(string $transferId, string $transferNo, Money $charge): ?Posting
    {
        if ($charge->isZero()) {
            return null;
        }

        return Posting::debit(LedgerEntryType::KitSupply, $charge, "Kit supply, transfer {$transferNo}", ReferenceType::STOCK_TRANSFER, $transferId, "kit:{$transferId}");
    }

    public static function walletToppedUp(string $topupId, Money $amount): Posting
    {
        return Posting::credit(LedgerEntryType::WalletTopup, $amount, 'Wallet top-up', ReferenceType::WALLET_TOPUP, $topupId, "topup:{$topupId}");
    }

    /**
     * Revenue-share settlement close: the patient money the franchise
     * collected is owed to HQ, and its commission on what it billed is owed
     * to the franchise. Net is HQ's share.
     *
     * @return list<Posting>
     */
    public static function revenueShareClosed(string $partnerKey, string $periodLabel, Money $cashCollected, Money $netBilled, string $commissionPct): array
    {
        $postings = [];

        if ($cashCollected->isGreaterThan(Money::zero())) {
            $postings[] = Posting::debit(LedgerEntryType::PartnerCharge, $cashCollected, "Patient payments collected, {$periodLabel}", ReferenceType::SETTLEMENT, null, "settlement-cash:{$partnerKey}:{$periodLabel}");
        }

        $commission = $netBilled->percent($commissionPct);
        if ($commission->isGreaterThan(Money::zero())) {
            $postings[] = Posting::credit(LedgerEntryType::Commission, $commission, "Commission {$commissionPct}% on Rs {$netBilled} billed, {$periodLabel}", ReferenceType::SETTLEMENT, null, "settlement-commission:{$partnerKey}:{$periodLabel}");
        }

        return $postings;
    }

    /** The money that settles a statement: received from the partner, or paid out to it. */
    public static function settlementPaid(string $settlementId, string $settlementNo, SettlementDirection $direction, Money $amount, string $paymentReference): ?Posting
    {
        return match ($direction) {
            SettlementDirection::PartnerPaysHq => Posting::credit(LedgerEntryType::PaymentReceived, $amount, "Payment received for settlement {$settlementNo} (ref {$paymentReference})", ReferenceType::SETTLEMENT, $settlementId, "settlement-paid:{$settlementId}"),
            SettlementDirection::HqPaysPartner => Posting::debit(LedgerEntryType::Payout, $amount, "Payout for settlement {$settlementNo} (ref {$paymentReference})", ReferenceType::SETTLEMENT, $settlementId, "settlement-paid:{$settlementId}"),
            SettlementDirection::Nil => null,
        };
    }
}
