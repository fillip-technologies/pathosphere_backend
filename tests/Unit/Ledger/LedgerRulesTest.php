<?php

namespace Tests\Unit\Ledger;

use App\Modules\Ledger\Domain\ChargedItem;
use App\Modules\Ledger\Domain\LedgerLine;
use App\Modules\Ledger\Domain\OverdueSince;
use App\Modules\Ledger\Domain\PartnerModel;
use App\Modules\Ledger\Domain\Posting;
use App\Modules\Ledger\Domain\PostingRules;
use App\Modules\Ledger\Domain\SettlementCalculator;
use App\Modules\Ledger\Domain\SettlementPeriod;
use App\Modules\Ledger\Domain\WalletSufficiency;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SettlementDirection;
use App\Modules\Network\Enums\SettlementCycle;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LedgerRulesTest extends TestCase
{
    public function test_order_charges_follow_the_partner_model(): void
    {
        $prices = ['item-1' => Money::fromString('210.00'), 'child' => Money::zero(), 'item-2' => Money::fromString('360.00')];

        $wholesale = PostingRules::orderConfirmed(PartnerModel::Wholesale, 'GAY-1', $prices);
        $this->assertSame(['charge:item-1', 'charge:item-2'], array_map(fn (Posting $p) => $p->idempotencyKey, $wholesale));
        $this->assertSame(['210.00', '360.00'], array_map(fn (Posting $p) => (string) $p->debit, $wholesale));
        $this->assertSame(LedgerEntryType::PartnerCharge, $wholesale[0]->entryType);

        $this->assertCount(2, PostingRules::orderConfirmed(PartnerModel::B2bClient, 'PCL-1', $prices));
        $this->assertSame([], PostingRules::orderConfirmed(PartnerModel::RevenueShare, 'DHN-1', $prices));
    }

    public function test_reversals_credit_each_charged_item_once(): void
    {
        $reversals = PostingRules::chargesReversed([new ChargedItem('item-1', Money::fromString('210.00'))], 'Order cancelled');

        $this->assertSame('210.00', (string) $reversals[0]->credit);
        $this->assertSame('reversal:item-1', $reversals[0]->idempotencyKey);
        $this->assertSame(LedgerEntryType::RefundReversal, $reversals[0]->entryType);
    }

    public function test_signing_posts_the_fee_and_deposit_and_skips_zero_amounts(): void
    {
        $postings = PostingRules::agreementSigned('agr', 'AGR/1', Money::fromString('25000'), Money::zero());

        $this->assertCount(1, $postings);
        $this->assertSame([LedgerEntryType::FranchiseFee, '25000.00', 'fee:agr'], [$postings[0]->entryType, (string) $postings[0]->debit, $postings[0]->idempotencyKey]);
        $this->assertNull(PostingRules::kitSupplyReceived('t', 'ST-1', Money::zero()));
    }

    public function test_revenue_share_close_debits_desk_cash_and_credits_commission(): void
    {
        [$cash, $commission] = PostingRules::revenueShareClosed('fr', '2026-10-01..2026-10-31', Money::fromString('950'), Money::fromString('2350'), '30.00');

        $this->assertSame(['950.00', '0.00'], [(string) $cash->debit, (string) $cash->credit]);
        $this->assertSame(['0.00', '705.00'], [(string) $commission->debit, (string) $commission->credit]);
        $this->assertSame(LedgerEntryType::Commission, $commission->entryType);
    }

    public function test_settlement_payments_move_money_the_right_way(): void
    {
        $received = PostingRules::settlementPaid('s', 'STL/1', SettlementDirection::PartnerPaysHq, Money::fromString('245'), 'UTR1');
        $paidOut = PostingRules::settlementPaid('s', 'STL/1', SettlementDirection::HqPaysPartner, Money::fromString('80'), 'UTR2');

        $this->assertSame([LedgerEntryType::PaymentReceived, '245.00'], [$received?->entryType, (string) $received?->credit]);
        $this->assertSame([LedgerEntryType::Payout, '80.00'], [$paidOut?->entryType, (string) $paidOut?->debit]);
        $this->assertNull(PostingRules::settlementPaid('s', 'STL/1', SettlementDirection::Nil, Money::zero(), ''));
    }

    public function test_a_posting_never_moves_zero_or_negative_money(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Posting::debit(LedgerEntryType::Adjustment, Money::zero(), 'Nothing', 'manual', null, null);
    }

    public function test_a_wallet_may_go_negative_down_to_its_credit_limit(): void
    {
        $limit = Money::fromString('1000');

        $this->assertTrue(WalletSufficiency::covers(Money::fromString('-500'), $limit, Money::fromString('500')));
        $this->assertFalse(WalletSufficiency::covers(Money::fromString('-500'), $limit, Money::fromString('500.01')));
        $this->assertSame('500.00', (string) WalletSufficiency::available(Money::fromString('-500'), $limit));
        $this->assertSame(80, WalletSufficiency::creditUsedPercent(Money::fromString('-800'), $limit));
        $this->assertSame(0, WalletSufficiency::creditUsedPercent(Money::fromString('300'), $limit));
        $this->assertSame(0, WalletSufficiency::creditUsedPercent(Money::fromString('-1'), Money::zero()));
    }

    public function test_settlement_periods_follow_the_cycle(): void
    {
        $tuesday = CarbonImmutable::parse('2026-10-06');

        $this->assertSame('2026-10-05..2026-10-11', SettlementPeriod::containing(SettlementCycle::Weekly, $tuesday)->label());
        $this->assertSame('2026-09-28..2026-10-04', SettlementPeriod::lastClosedBefore(SettlementCycle::Weekly, $tuesday)->label());
        $this->assertSame('2026-09-16..2026-09-30', SettlementPeriod::lastClosedBefore(SettlementCycle::Fortnightly, $tuesday)->label());
        $this->assertSame('2026-10-01..2026-10-15', SettlementPeriod::lastClosedBefore(SettlementCycle::Fortnightly, CarbonImmutable::parse('2026-10-16'))->label());
        $this->assertSame('2026-09-01..2026-09-30', SettlementPeriod::lastClosedBefore(SettlementCycle::Monthly, $tuesday)->label());
        $this->assertSame('2026-02-01..2026-02-28', SettlementPeriod::lastClosedBefore(SettlementCycle::Monthly, CarbonImmutable::parse('2026-03-01'))->label());
    }

    public function test_revenue_share_settlement_splits_billing_and_settles_the_balance(): void
    {
        $lines = [
            new LedgerLine(LedgerEntryType::PartnerCharge, Money::fromString('950'), Money::zero()),
            new LedgerLine(LedgerEntryType::Commission, Money::zero(), Money::fromString('705')),
        ];

        $owed = SettlementCalculator::calculate(PartnerModel::RevenueShare, Money::fromString('2350'), $lines, Money::fromString('-245'));
        $this->assertSame(['2350.00', '705.00', '1645.00', '245.00'], [(string) $owed->grossBilling, (string) $owed->partnerShare, (string) $owed->hqShare, (string) $owed->netAmount]);
        $this->assertSame(SettlementDirection::PartnerPaysHq, $owed->direction);

        // Online money held by HQ can leave HQ owing the franchise its commission.
        $payout = SettlementCalculator::calculate(PartnerModel::RevenueShare, Money::fromString('1000'), [], Money::fromString('300'));
        $this->assertSame([SettlementDirection::HqPaysPartner, '300.00'], [$payout->direction, (string) $payout->netAmount]);
    }

    public function test_wholesale_and_b2b_keep_a_credit_balance_and_collect_a_debit_one(): void
    {
        $charges = [
            new LedgerLine(LedgerEntryType::PartnerCharge, Money::fromString('780'), Money::zero()),
            new LedgerLine(LedgerEntryType::RefundReversal, Money::zero(), Money::fromString('210')),
            new LedgerLine(LedgerEntryType::WalletTopup, Money::zero(), Money::fromString('2000')),
        ];

        $wallet = SettlementCalculator::calculate(PartnerModel::Wholesale, Money::fromString('950'), $charges, Money::fromString('930'));
        $this->assertSame(['570.00', '380.00', 'nil', '0.00'], [(string) $wallet->hqShare, (string) $wallet->partnerShare, $wallet->direction->value, (string) $wallet->netAmount]);

        $client = SettlementCalculator::calculate(PartnerModel::B2bClient, Money::zero(), $charges, Money::fromString('-570'));
        $this->assertSame(['570.00', '570.00', '0.00', 'partner_pays_hq', '570.00'], [
            (string) $client->grossBilling, (string) $client->hqShare, (string) $client->partnerShare, $client->direction->value, (string) $client->netAmount,
        ]);
        $this->assertSame(SettlementDirection::Nil, SettlementCalculator::calculate(PartnerModel::B2bClient, Money::zero(), [], Money::fromString('50'))->direction);
    }

    public function test_overdue_since_finds_the_start_of_the_current_run_past_the_limit(): void
    {
        $row = fn (string $balance, string $at) => ['balance_after' => Money::fromString($balance), 'created_at' => CarbonImmutable::parse($at)];
        $limit = Money::fromString('1000');

        $newestFirst = [$row('-1500', '2026-10-05'), $row('-1200', '2026-10-01'), $row('-900', '2026-09-20'), $row('-1100', '2026-09-10')];
        $this->assertSame('2026-10-01', OverdueSince::find($newestFirst, $limit)?->toDateString());
        $this->assertNull(OverdueSince::find([$row('-1000', '2026-10-05')], $limit));
    }
}
