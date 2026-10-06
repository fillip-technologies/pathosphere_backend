<?php

namespace Tests\Unit\Ledger;

use App\Modules\Ledger\Contracts\JournalBook;
use App\Modules\Ledger\Domain\AccountChart;
use App\Modules\Ledger\Domain\AccountingJournal;
use App\Modules\Ledger\Domain\BookAccount;
use App\Modules\Ledger\Domain\JournalLine;
use App\Modules\Ledger\Domain\JournalVoucher;
use App\Modules\Ledger\Domain\MoneyDay;
use App\Modules\Ledger\Domain\PartnerMovement;
use App\Modules\Ledger\Domain\SalesDay;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\VoucherType;
use App\Modules\Ledger\Infrastructure\TallyXmlJournal;
use App\Modules\Ledger\Infrastructure\ZohoBooksJournalCsv;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/** The accounting export (spec §3): vouchers that balance, in Tally's and Zoho's formats. */
final class AccountingJournalTest extends TestCase
{
    private AccountChart $chart;

    private CarbonImmutable $day;

    protected function setUp(): void
    {
        $account = fn (string $name, string $group) => ['name' => $name, 'group' => $group];
        $this->chart = new AccountChart([
            'accounts' => [
                'patients' => $account('Patients - {branch_code}', 'Sundry Debtors'),
                'b2b_client' => $account('{name} ({code})', 'Sundry Debtors'),
                'franchise' => $account('{name} ({code})', 'Sundry Debtors'),
                'franchise_online_collections' => $account('Franchise Online Collections', 'Suspense A/c'),
                'sales' => $account('Diagnostic Services', 'Sales Accounts'),
                'discount' => $account('Discount Allowed', 'Indirect Expenses'),
                'output_tax' => $account('Output GST', 'Duties & Taxes'),
            ],
            'money_accounts' => ['cash' => $account('Cash - {branch_code}', 'Cash-in-Hand')],
            'partner_entry_accounts' => [
                'wallet_topup' => $account('Bank - Collections', 'Bank Accounts'),
                'partner_charge' => $account('Franchise Test Charges', 'Sales Accounts'),
            ],
        ]);
        $this->day = CarbonImmutable::parse('2026-09-15');
    }

    public function test_a_days_sales_carry_the_gross_with_discount_allowed_and_balance(): void
    {
        $voucher = AccountingJournal::sales($this->chart, $this->salesDay('1300.00', '95.00', '0.00', '1205.00'));

        $this->assertNotNull($voucher);
        $this->assertSame(VoucherType::Sales, $voucher->type);
        $this->assertSame('S/PATPSC1/20260915/PAT', $voucher->reference);
        $this->assertSame('2 invoices to Patients - PATPSC1 at PATPSC1, 15 Sep 2026', $voucher->narration);
        $this->assertSame([
            ['Patients - PATPSC1', 'Dr', '1205.00'],
            ['Discount Allowed', 'Dr', '95.00'],
            ['Diagnostic Services', 'Cr', '1300.00'],
        ], $this->lines($voucher));
        $this->assertSame('Patients - PATPSC1', $voucher->party()?->name);
    }

    public function test_tax_is_credited_when_charged_and_empty_days_make_no_voucher(): void
    {
        $taxed = AccountingJournal::sales($this->chart, $this->salesDay('1000.00', '0.00', '180.00', '1180.00'));

        $this->assertSame([
            ['Patients - PATPSC1', 'Dr', '1180.00'],
            ['Diagnostic Services', 'Cr', '1000.00'],
            ['Output GST', 'Cr', '180.00'],
        ], $taxed === null ? [] : $this->lines($taxed));
        $this->assertNull(AccountingJournal::sales($this->chart, $this->salesDay('0.00', '0.00', '0.00', '0.00')));
    }

    public function test_receipts_debit_the_cash_and_refunds_reverse_them(): void
    {
        $receipt = AccountingJournal::money($this->moneyDay(false));
        $refund = AccountingJournal::money($this->moneyDay(true));

        $this->assertSame(VoucherType::Receipt, $receipt?->type);
        $this->assertSame('R/PATPSC1/20260915/PAT/CASH', $receipt->reference);
        $this->assertSame([['Cash - PATPSC1', 'Dr', '350.00'], ['Patients - PATPSC1', 'Cr', '350.00']], $this->lines($receipt));

        $this->assertSame(VoucherType::Payment, $refund?->type);
        $this->assertSame('RF/PATPSC1/20260915/PAT/CASH', $refund->reference);
        $this->assertSame([['Patients - PATPSC1', 'Dr', '350.00'], ['Cash - PATPSC1', 'Cr', '350.00']], $this->lines($refund));
    }

    public function test_a_franchise_month_books_each_row_type_against_its_own_account(): void
    {
        $gaya = $this->chart->franchise('FRGAYA', 'Gaya Diagnostics');
        $voucher = AccountingJournal::partnerMonth($this->chart, CarbonImmutable::parse('2026-09-30'), 'FRGAYA', $gaya, [
            new PartnerMovement(LedgerEntryType::WalletTopup, Money::zero(), Money::fromString('1000.00')),
            new PartnerMovement(LedgerEntryType::PartnerCharge, Money::fromString('420.00'), Money::fromString('210.00')),
        ]);

        $this->assertSame('PL/FRGAYA/202609', $voucher?->reference);
        $this->assertSame([
            ['Bank - Collections', 'Dr', '1000.00'],
            ['Gaya Diagnostics (FRGAYA)', 'Cr', '1000.00'],
            ['Gaya Diagnostics (FRGAYA)', 'Dr', '420.00'],
            ['Franchise Test Charges', 'Cr', '420.00'],
            ['Franchise Test Charges', 'Dr', '210.00'],
            ['Gaya Diagnostics (FRGAYA)', 'Cr', '210.00'],
        ], $this->lines($voucher));
    }

    public function test_vouchers_must_balance_and_accounts_must_be_configured(): void
    {
        $account = new BookAccount('Anything', 'Suspense A/c');

        try {
            new JournalVoucher(VoucherType::Journal, $this->day, 'X', 'x', [
                JournalLine::debit($account, Money::fromString('10.00')),
                JournalLine::credit($account, Money::fromString('9.99')),
            ]);
            $this->fail('An unbalanced voucher was accepted.');
        } catch (InvalidArgumentException $unbalanced) {
            $this->assertStringContainsString('does not balance', $unbalanced->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->chart->money('upi', 'PATPSC1');
    }

    public function test_tally_xml_creates_each_ledger_once_and_uses_tallys_signs(): void
    {
        $xml = (new TallyXmlJournal)->write($this->book());
        $envelope = simplexml_load_string($xml);

        $this->assertNotFalse($envelope);
        $this->assertSame('Pathology Network & Co', (string) $envelope->BODY->IMPORTDATA->REQUESTDESC->STATICVARIABLES->SVCURRENTCOMPANY);
        $ledgers = $envelope->xpath('//LEDGER/@NAME') ?: [];
        $this->assertSame(['Patients - PATPSC1', 'Discount Allowed', 'Diagnostic Services', 'Cash - PATPSC1'], array_map('strval', $ledgers));

        $sale = $envelope->xpath('//VOUCHER[@VCHTYPE="Sales"]')[0] ?? null;
        $this->assertNotNull($sale);
        $this->assertSame('20260915', (string) $sale->DATE);
        $this->assertSame('Patients - PATPSC1', (string) $sale->PARTYLEDGERNAME);
        $this->assertSame(['-1205.00', '-95.00', '1300.00'], array_map(fn ($line) => (string) $line->AMOUNT, $sale->xpath('ALLLEDGERENTRIES.LIST') ?: []));
        $this->assertSame(['Yes', 'Yes', 'No'], array_map(fn ($line) => (string) $line->ISDEEMEDPOSITIVE, $sale->xpath('ALLLEDGERENTRIES.LIST') ?: []));
    }

    public function test_zoho_csv_books_parties_to_receivables_and_defuses_formulas(): void
    {
        $risky = new BookAccount('=HYPERLINK("x") (CLX)', 'Sundry Debtors', isParty: true);
        $voucher = AccountingJournal::sales($this->chart, new SalesDay($this->day, 'PATCL1', 'CLX', $risky, 1, Money::fromString('245.00'), Money::zero(), Money::zero(), Money::fromString('245.00')));
        $csv = (new ZohoBooksJournalCsv('Accounts Receivable'))->write(new JournalBook('Co', 'INR', $this->day, $this->day, $voucher === null ? [] : [$voucher]));

        $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));

        $this->assertSame(['Journal Date', 'Reference Number', 'Notes', 'Currency Code', 'Account', 'Description', 'Contact Name', 'Debit', 'Credit'], $rows[0]);
        $this->assertSame(['2026-09-15', 'S/PATCL1/20260915/CLX', 'Accounts Receivable', '\'=HYPERLINK("x") (CLX)', '245.00', ''], [$rows[1][0], $rows[1][1], $rows[1][4], $rows[1][6], $rows[1][7], $rows[1][8]]);
        $this->assertSame(['Diagnostic Services', '', '', '245.00'], [$rows[2][4], $rows[2][6], $rows[2][7], $rows[2][8]]);
    }

    private function salesDay(string $amount, string $discount, string $tax, string $total): SalesDay
    {
        return new SalesDay(
            $this->day,
            'PATPSC1',
            'PAT',
            $this->chart->patients('PATPSC1'),
            2,
            Money::fromString($amount),
            Money::fromString($discount),
            Money::fromString($tax),
            Money::fromString($total),
        );
    }

    private function moneyDay(bool $isRefund): MoneyDay
    {
        return new MoneyDay($this->day, 'PATPSC1', 'PAT', $this->chart->patients('PATPSC1'), 'cash', $this->chart->money('cash', 'PATPSC1'), $isRefund, 1, Money::fromString('350.00'));
    }

    private function book(): JournalBook
    {
        return new JournalBook('Pathology Network & Co', 'INR', $this->day, $this->day, array_values(array_filter([
            AccountingJournal::sales($this->chart, $this->salesDay('1300.00', '95.00', '0.00', '1205.00')),
            AccountingJournal::money($this->moneyDay(false)),
        ])));
    }

    /** @return list<array{string, string, string}> */
    private function lines(JournalVoucher $voucher): array
    {
        return array_map(fn (JournalLine $line) => [$line->account->name, $line->isDebit() ? 'Dr' : 'Cr', $line->amount()->toDecimalString()], $voucher->lines);
    }
}
