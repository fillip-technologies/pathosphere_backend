<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Ledger\Enums\VoucherType;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Turns the platform's money into vouchers for the company's books (spec §3:
 * export only; the platform is not the books of account), pure.
 *
 * - Sales: the party owes the bill; discount is shown as allowed, so sales
 *   carry the gross amount. Dr party + Dr discount = Cr sales + Cr tax.
 * - Receipts: Dr where the money went (cash, bank), Cr the party.
 * - Refunds: the reverse, as payment vouchers.
 * - A franchise's month: each ledger row type against its own account. A
 *   debit row (owed to HQ) is Dr franchise; a credit row is Cr franchise.
 *
 * Nothing with a zero amount becomes a voucher.
 */
final class AccountingJournal
{
    public static function sales(AccountChart $chart, SalesDay $day): ?JournalVoucher
    {
        $lines = self::nonZero([
            [true, $day->party, $day->total],
            [true, $chart->discount(), $day->discount],
            [false, $chart->sales(), $day->amount],
            [false, $chart->outputTax(), $day->tax],
        ]);

        if ($lines === []) {
            return null;
        }

        $invoices = $day->invoiceCount === 1 ? '1 invoice' : "{$day->invoiceCount} invoices";

        return new JournalVoucher(
            VoucherType::Sales,
            $day->date,
            sprintf('S/%s/%s/%s', $day->branchCode, $day->date->format('Ymd'), $day->partyCode),
            sprintf('%s to %s at %s, %s', $invoices, $day->party->name, $day->branchCode, $day->date->format('d M Y')),
            $lines,
        );
    }

    public static function money(MoneyDay $day): ?JournalVoucher
    {
        if ($day->amount->isZero()) {
            return null;
        }

        $lines = $day->isRefund
            ? [JournalLine::debit($day->party, $day->amount), JournalLine::credit($day->moneyAccount, $day->amount)]
            : [JournalLine::debit($day->moneyAccount, $day->amount), JournalLine::credit($day->party, $day->amount)];

        return new JournalVoucher(
            $day->isRefund ? VoucherType::Payment : VoucherType::Receipt,
            $day->date,
            sprintf('%s/%s/%s/%s/%s', $day->isRefund ? 'RF' : 'R', $day->branchCode, $day->date->format('Ymd'), $day->partyCode, strtoupper($day->mode)),
            sprintf('%s by %s (%d) from %s at %s, %s', $day->isRefund ? 'Refunds' : 'Receipts', $day->mode, $day->count, $day->party->name, $day->branchCode, $day->date->format('d M Y')),
            $lines,
        );
    }

    /** @param  list<PartnerMovement>  $movements */
    public static function partnerMonth(AccountChart $chart, CarbonImmutable $monthEnd, string $partnerCode, BookAccount $partner, array $movements): ?JournalVoucher
    {
        $lines = [];
        foreach ($movements as $movement) {
            $other = $chart->partnerEntry($movement->entryType);
            $lines = [
                ...$lines,
                ...self::nonZero([[true, $partner, $movement->debit], [false, $other, $movement->debit]]),
                ...self::nonZero([[true, $other, $movement->credit], [false, $partner, $movement->credit]]),
            ];
        }

        if ($lines === []) {
            return null;
        }

        return new JournalVoucher(
            VoucherType::Journal,
            $monthEnd,
            sprintf('PL/%s/%s', $partnerCode, $monthEnd->format('Ym')),
            sprintf('Partner account of %s, %s (from the partner ledger)', $partner->name, $monthEnd->format('F Y')),
            $lines,
        );
    }

    /**
     * @param  list<array{bool, BookAccount, Money}>  $sides  [is debit, account, amount]
     * @return list<JournalLine>
     */
    private static function nonZero(array $sides): array
    {
        $lines = [];
        foreach ($sides as [$isDebit, $account, $amount]) {
            if ($amount->isGreaterThan(Money::zero())) {
                $lines[] = $isDebit ? JournalLine::debit($account, $amount) : JournalLine::credit($account, $amount);
            }
        }

        return $lines;
    }
}
