<?php

namespace Tests\Feature\Ledger;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Ledger\Jobs\GenerateAccountingExport;
use App\Modules\Ledger\Services\AccountingExports;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use SimpleXMLElement;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Ledger\MovesMoney;
use Tests\TestCase;

/**
 * Monthly journals for the company's books (spec §3: Tally XML, Zoho Books).
 * September's business is booked on 15 Sep and exported on 2 Oct.
 */
final class AccountingExportTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use MovesMoney;
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        // The seed posts franchise fees and deposits; keep them out of September.
        $this->travelTo(CarbonImmutable::parse('2026-08-20 09:00', 'Asia/Kolkata'));
        $this->useTestGatewaySecret();
        $this->setUpDemoNetwork();
        $this->finance = $this->staff(SystemRole::HqFinance);
    }

    public function test_a_months_sales_receipts_and_refunds_go_to_tally_as_balanced_vouchers(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 11:00', 'Asia/Kolkata'));
        $desk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATPSC1')]);
        $patientId = $this->actingAsStaff($desk)->registerPatient()['id'];

        $this->bookOrder($patientId, 'PATPSC1', [
            'items' => [$this->testItem('CBC'), $this->testItem('LIPID')],
            'discount' => ['amount' => '95.00', 'reason' => 'Senior citizen'],
            'payment' => ['mode' => 'cash', 'amount' => '855.00'],
        ])->assertCreated();
        $this->bookOrder($patientId, 'PATPSC1', ['items' => [$this->testItem('CBC')], 'payment' => ['mode' => 'upi', 'amount' => '350.00']])->assertCreated();

        // Cancelled the same day: no sale, and the money in and out nets to zero.
        $cancelled = $this->bookOrder($patientId, 'PATPSC1', ['items' => [$this->testItem('LIPID')], 'payment' => ['mode' => 'cash', 'amount' => '600.00']])->json('data');
        $this->actingAsStaff($this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('PATPSC1')]))
            ->postJson("/api/v1/orders/{$cancelled['id']}/cancel", ['reason' => 'Patient left'])->assertOk();

        // A B2B client billed on credit, and a franchise branch's own sale (not HQ's).
        $clientInvoiceTotal = $this->actingAsStaff($this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATCL1')]))
            ->bookOrder($patientId, 'PATCL1', ['order_source' => 'b2b', 'b2b_client_id' => $this->clientId('CLPCH'), 'items' => [$this->testItem('CBC')]])
            ->json('data.invoices.0.total');
        $this->actingAsStaff($this->staff(SystemRole::FranchiseOwner, ['franchise_id' => $this->franchiseId('FRGAYA')]))->topUpWallet('1000.00');
        $this->actingAsStaff($this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('GAYPSC1')]))
            ->bookOrder($patientId, 'GAYPSC1', ['items' => [$this->testItem('CBC')], 'payment' => ['mode' => 'cash', 'amount' => '350.00']])->assertCreated();

        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
        $export = $this->actingAsStaff($this->finance)
            ->postJson('/api/v1/accounting-exports', ['kind' => 'sales', 'format' => 'tally_xml', 'month' => '2026-09'])
            ->assertAccepted()
            ->assertHeader('Location')
            ->assertJsonPath('data.period_start', '2026-09-01')
            ->assertJsonPath('data.period_end', '2026-09-30')
            ->json('data');

        $this->getJson("/api/v1/accounting-exports/{$export['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.voucher_count', 5)
            ->assertJsonPath('data.file_url', "/api/v1/accounting-exports/{$export['id']}/file");

        $file = $this->get("/api/v1/accounting-exports/{$export['id']}/file")->assertOk();
        $this->assertStringContainsString('sales-2026-09-tally-xml.xml', (string) $file->headers->get('Content-Disposition'));
        $vouchers = $this->tallyVouchers($file->streamedContent());
        ksort($vouchers);

        $this->assertSame([
            'R/PATPSC1/20260915/PAT/CASH' => [['Cash - PATPSC1', '-1455.00'], ['Patients - PATPSC1', '1455.00']],
            'R/PATPSC1/20260915/PAT/UPI' => [['Bank - Collections', '-350.00'], ['Patients - PATPSC1', '350.00']],
            'RF/PATPSC1/20260915/PAT/CASH' => [['Patients - PATPSC1', '-600.00'], ['Cash - PATPSC1', '600.00']],
            'S/PATCL1/20260915/CLPCH' => [['Patna City Hospital (CLPCH)', '-'.$clientInvoiceTotal], ['Diagnostic Services', $clientInvoiceTotal]],
            'S/PATPSC1/20260915/PAT' => [['Patients - PATPSC1', '-1205.00'], ['Discount Allowed', '-95.00'], ['Diagnostic Services', '1300.00']],
        ], $vouchers, 'The franchise sale at GAYPSC1 is its own; HQ sees it through the partner ledger.');
    }

    public function test_a_franchise_month_goes_to_zoho_against_its_contact(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00', 'Asia/Kolkata'));
        $this->actingAsStaff($this->staff(SystemRole::FranchiseOwner, ['franchise_id' => $this->franchiseId('FRGAYA')]))->topUpWallet('1000.00');
        $desk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('GAYPSC1')]);
        $patientId = $this->actingAsStaff($desk)->registerPatient()['id'];
        $this->bookOrder($patientId, 'GAYPSC1', ['items' => [$this->testItem('CBC')], 'payment' => ['mode' => 'cash', 'amount' => '350.00']])->assertCreated();

        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
        $exportId = $this->actingAsStaff($this->finance)
            ->postJson('/api/v1/accounting-exports', ['kind' => 'partner_ledger', 'format' => 'zoho_books_csv', 'month' => '2026-09'])
            ->assertAccepted()->json('data.id');

        $this->getJson("/api/v1/accounting-exports/{$exportId}")->assertJsonPath('data.voucher_count', 1)->assertJsonPath('data.total_amount', '1210.00');
        $csv = $this->get("/api/v1/accounting-exports/{$exportId}/file")->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", $csv))));

        $this->assertSame([
            ['PL/FRGAYA/202609', 'Bank - Collections', '', '1000.00', ''],
            ['PL/FRGAYA/202609', 'Accounts Receivable', 'Gaya Diagnostics (FRGAYA)', '', '1000.00'],
            ['PL/FRGAYA/202609', 'Accounts Receivable', 'Gaya Diagnostics (FRGAYA)', '210.00', ''],
            ['PL/FRGAYA/202609', 'Franchise Test Charges', '', '', '210.00'],
        ], array_map(fn (array $row) => [$row[1], $row[4], $row[6], $row[7], $row[8]], array_slice($rows, 1)));
        $this->assertSame('2026-09-30', $rows[1][0]);
    }

    public function test_only_hq_finance_exports_and_only_months_that_have_ended(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
        $request = ['kind' => 'sales', 'format' => 'tally_xml', 'month' => '2026-10'];

        $this->actingAsStaff($this->finance)->postJson('/api/v1/accounting-exports', $request)
            ->assertStatus(422)->assertJsonPath('error.code', 'ACCOUNTING_PERIOD_OPEN');
        $this->postJson('/api/v1/accounting-exports', [...$request, 'month' => '09-2026'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'month');

        foreach ([
            $this->staff(SystemRole::FranchiseOwner, ['franchise_id' => $this->franchiseId('FRGAYA')]),
            $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('PATPSC1')]),
            $this->staff(SystemRole::HqOperations),
        ] as $notFinance) {
            $this->actingAsStaff($notFinance)->postJson('/api/v1/accounting-exports', [...$request, 'month' => '2026-09'])->assertForbidden();
            $this->getJson('/api/v1/accounting-exports')->assertForbidden();
        }
    }

    public function test_the_file_waits_while_building_and_a_failed_export_says_so(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
        Queue::fake();
        $exportId = $this->actingAsStaff($this->finance)
            ->postJson('/api/v1/accounting-exports', ['kind' => 'sales', 'format' => 'zoho_books_csv', 'month' => '2026-09'])
            ->assertAccepted()->assertJsonPath('data.status', 'queued')->json('data.id');
        Queue::assertPushed(GenerateAccountingExport::class, fn (GenerateAccountingExport $job) => $job->exportId === $exportId);

        $this->get("/api/v1/accounting-exports/{$exportId}/file")->assertAccepted()->assertHeader('Retry-After', '30');

        $this->asSystem(fn () => app(AccountingExports::class)->markFailed($exportId, 'No account is configured for upi (pathology.accounting).'));
        $this->getJson("/api/v1/accounting-exports/{$exportId}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_message', 'No account is configured for upi (pathology.accounting).');
        $this->get("/api/v1/accounting-exports/{$exportId}/file")->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNTING_EXPORT_FAILED');
        $this->getJson('/api/v1/accounting-exports?filter[status]=failed')->assertJsonCount(1, 'data');
    }

    /**
     * Every voucher's lines (ledger, Tally amount) by voucher number, checking each one balances.
     *
     * @return array<string, list<array{string, string}>>
     */
    private function tallyVouchers(string $xml): array
    {
        $envelope = simplexml_load_string($xml);
        $this->assertInstanceOf(SimpleXMLElement::class, $envelope);
        $vouchers = [];

        foreach ($envelope->xpath('//VOUCHER') ?: [] as $voucher) {
            $lines = array_map(fn (SimpleXMLElement $line) => [(string) $line->LEDGERNAME, (string) $line->AMOUNT], $voucher->xpath('ALLLEDGERENTRIES.LIST') ?: []);
            $balance = array_reduce($lines, fn (int $paise, array $line) => $paise + (str_starts_with($line[1], '-') ? -Money::fromString(substr($line[1], 1))->paise() : Money::fromString($line[1])->paise()), 0);
            $this->assertSame(0, $balance, "Voucher {$voucher->VOUCHERNUMBER} does not balance.");
            $vouchers[(string) $voucher->VOUCHERNUMBER] = $lines;
        }

        return $vouchers;
    }
}
