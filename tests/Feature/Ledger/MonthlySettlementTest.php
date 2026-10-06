<?php

namespace Tests\Feature\Ledger;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Booking\Models\Invoice;
use App\Modules\Ledger\Jobs\BuildSettlements;
use App\Modules\Ledger\Models\Settlement;
use App\Modules\Network\Models\B2bClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Ledger\MovesMoney;
use Tests\TestCase;

/**
 * Phase 6 "done when" (spec §12): one franchise per billing model and one B2B
 * client run a full monthly settlement, entirely through the API and the
 * nightly job.
 *
 * October on the demo network:
 * - Gaya Diagnostics (wholesale, prepaid wallet) tops up Rs 2000, books CBC +
 *   lipid (partner price 210 + 360), books and cancels a TSH (210 charged and
 *   reversed), and receives Rs 500 of tubes from the reference lab.
 * - Dhanbad Health Point (revenue share, 30%) books CBC + lipid paid in cash
 *   at its desk (950) and vitamin D paid online to HQ (1400).
 * - Patna City Hospital (B2B) sends CBC + vitamin D on credit at client
 *   prices (245 + 980).
 */
final class MonthlySettlementTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use MovesMoney;
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Kolkata'));
        $this->useTestGatewaySecret();
        $this->setUpDemoNetwork();
        $this->finance = $this->staff(SystemRole::HqFinance);
    }

    public function test_one_franchise_per_billing_model_and_a_b2b_client_run_a_full_monthly_settlement(): void
    {
        $this->runOctoberAtGaya();
        $this->runOctoberAtDhanbad();
        $clientCharges = $this->runOctoberAtPatnaCityHospital();

        // The nightly job after the month closes builds one settlement per partner.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 02:00', 'Asia/Kolkata'));
        dispatch_sync(new BuildSettlements);

        $gaya = $this->settlementOf('franchise_id', $this->franchiseId('FRGAYA'));
        $this->assertSettlement($gaya, [
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'gross_billing' => '950.00', 'hq_share' => '570.00', 'partner_share' => '380.00',
            'closing_balance' => '930.00', 'direction' => 'nil', 'net_amount' => '0.00', 'status' => 'pending_approval',
        ]);
        // Fee, deposit, top-up, three test charges, the reversal and the kits.
        $this->assertCount(8, $gaya['entries']);

        $dhanbad = $this->settlementOf('franchise_id', $this->franchiseId('FRDHN'));
        $this->assertSettlement($dhanbad, [
            'gross_billing' => '2350.00', 'partner_share' => '705.00', 'hq_share' => '1645.00',
            'closing_balance' => '-245.00', 'direction' => 'partner_pays_hq', 'net_amount' => '245.00',
        ]);
        $closingEntries = array_filter($dhanbad['entries'], fn (array $entry) => in_array($entry['entry_type'], ['partner_charge', 'commission'], true));
        $this->assertSame(
            [['Patient payments collected, 2026-10-01..2026-10-31', '950.00', '0.00'], ['Commission 30.00% on Rs 2350.00 billed, 2026-10-01..2026-10-31', '0.00', '705.00']],
            array_values(array_map(fn (array $entry) => [$entry['narration'], $entry['debit'], $entry['credit']], $closingEntries)),
        );

        $hospital = $this->settlementOf('b2b_client_id', $this->clientId('CLPCH'));
        $this->assertSettlement($hospital, [
            'gross_billing' => $clientCharges, 'hq_share' => $clientCharges, 'partner_share' => '0.00',
            'closing_balance' => "-{$clientCharges}", 'direction' => 'partner_pays_hq', 'net_amount' => $clientCharges,
        ]);

        // Statements are rendered, stored and announced to each partner.
        $this->actingAsStaff($this->finance)->get("/api/v1/settlements/{$dhanbad['id']}/statement")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertCount(3, collect($this->messages->sent)->filter(fn (array $message) => str_contains($message['body'], 'settlement STL/')));

        // HQ Finance approves and records the payments.
        foreach ([$gaya, $dhanbad, $hospital] as $settlement) {
            $this->actingAsStaff($this->finance)->postJson("/api/v1/settlements/{$settlement['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        }
        $this->actingAsStaff($this->finance)->postJson("/api/v1/settlements/{$dhanbad['id']}/mark-settled")
            ->assertStatus(422)->assertJsonPath('error.code', 'PAYMENT_REFERENCE_REQUIRED');
        $this->actingAsStaff($this->finance)->postJson("/api/v1/settlements/{$dhanbad['id']}/mark-settled", ['payment_reference' => 'UTR0001DHN'])
            ->assertOk()->assertJsonPath('data.status', 'settled')->assertJsonPath('data.payment_reference', 'UTR0001DHN');
        $this->actingAsStaff($this->finance)->postJson("/api/v1/settlements/{$hospital['id']}/mark-settled", ['payment_reference' => 'UTR0002PCH'])->assertOk();
        $this->actingAsStaff($this->finance)->postJson("/api/v1/settlements/{$gaya['id']}/mark-settled")->assertOk()->assertJsonPath('data.payment_reference', null);

        // Paying the statement brings the postpaid accounts back to zero; the wallet keeps its money.
        $this->assertSame('0.00', $this->franchiseBalance('FRDHN'));
        $this->assertSame('0.00', $this->asSystem(fn () => (string) B2bClient::query()->where('client_code', 'CLPCH')->firstOrFail()->current_balance));
        // The hospital was billed at its own rates, and paying the settlement paid that invoice.
        $this->assertSame([['1225.00', 'paid']], $this->asSystem(fn () => Invoice::query()->where('b2b_client_id', $this->clientId('CLPCH'))->get()
            ->map(fn (Invoice $invoice) => [(string) $invoice->total, $invoice->payment_status->value])->all()));
        $this->assertSame('930.00', $this->franchiseBalance('FRGAYA'));
        $this->assertLedgerAddsUp('franchise_id', $this->franchiseId('FRGAYA'), '930.00');
        $this->assertLedgerAddsUp('franchise_id', $this->franchiseId('FRDHN'), '0.00');
        $this->assertLedgerAddsUp('b2b_client_id', $this->clientId('CLPCH'), '0.00');

        // Each partner sees its own statement and nobody else's.
        $gayaOwner = $this->staff(SystemRole::FranchiseOwner, ['franchise_id' => $this->franchiseId('FRGAYA')]);
        $this->actingAsStaff($gayaOwner)->getJson('/api/v1/settlements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $gaya['id']);
        $this->actingAsStaff($gayaOwner)->getJson("/api/v1/settlements/{$dhanbad['id']}")->assertNotFound();
        $clientUser = $this->staff(SystemRole::B2bClientUser, ['b2b_client_id' => $this->clientId('CLPCH')]);
        $this->actingAsStaff($clientUser)->getJson("/api/v1/settlements/{$hospital['id']}")->assertOk();

        // Running the job again, or next month with nothing new, builds nothing and settles nothing twice.
        dispatch_sync(new BuildSettlements);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 02:00', 'Asia/Kolkata'));
        dispatch_sync(new BuildSettlements);
        $this->assertSame(3, $this->asSystem(fn () => Settlement::query()->count()));
    }

    private function runOctoberAtGaya(): void
    {
        $owner = $this->staff(SystemRole::FranchiseOwner, ['franchise_id' => $this->franchiseId('FRGAYA')]);
        $desk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('GAYPSC1')]);
        $admin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('GAYPSC1')]);

        $this->actingAsStaff($owner)->topUpWallet('2000.00');
        $this->assertSame('2000.00', $this->franchiseBalance('FRGAYA'));

        $patient = $this->actingAsStaff($desk)->registerPatient();
        $this->bookOrder($patient['id'], 'GAYPSC1', [
            'items' => [$this->testItem('CBC'), $this->testItem('LIPID')],
            'payment' => ['mode' => 'cash', 'amount' => '950.00'],
        ])->assertCreated()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame('1430.00', $this->franchiseBalance('FRGAYA'));

        $cancelled = $this->bookOrder($patient['id'], 'GAYPSC1', [
            'items' => [$this->testItem('TSH')],
            'payment' => ['mode' => 'cash', 'amount' => '350.00'],
        ])->assertCreated()->json('data');
        $this->actingAsStaff($admin)->postJson("/api/v1/orders/{$cancelled['id']}/cancel", ['reason' => 'Patient left'])->assertOk();
        $this->assertLedgerAddsUp('franchise_id', $this->franchiseId('FRGAYA'), '1430.00');

        // Tubes from the reference lab, charged to the franchise on receipt.
        $referenceAdmin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('PATREF')]);
        $this->actingAsStaff($referenceAdmin)->postJson('/api/v1/inventory-items', [
            'branch_id' => $this->branchId('PATREF'), 'item_code' => 'EDTA-3ML', 'name' => 'EDTA tube 3 ml',
            'category' => 'tube', 'unit' => 'box', 'quantity' => '40', 'batch_no' => 'B2610',
        ])->assertCreated();
        $transfer = $this->postJson('/api/v1/stock-transfers', [
            'from_branch_id' => $this->branchId('PATREF'), 'to_branch_id' => $this->branchId('GAYPSC1'),
            'item_code' => 'EDTA-3ML', 'batch_no' => 'B2610', 'quantity' => '10', 'charge_amount' => '500.00',
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/stock-transfers/{$transfer['id']}/dispatch")->assertOk();
        $this->actingAsStaff($admin)->postJson("/api/v1/stock-transfers/{$transfer['id']}/receive")->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame('930.00', $this->franchiseBalance('FRGAYA'));
    }

    private function runOctoberAtDhanbad(): void
    {
        $desk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('DHNPSC1')]);
        $patient = $this->actingAsStaff($desk)->registerPatient(['phone' => '9876505678']);

        $this->bookOrder($patient['id'], 'DHNPSC1', [
            'items' => [$this->testItem('CBC'), $this->testItem('LIPID')],
            'payment' => ['mode' => 'cash', 'amount' => '950.00'],
        ])->assertCreated()->assertJsonPath('data.status', 'confirmed');

        // Paid online: the money lands in HQ's gateway account, not the franchise's till.
        $online = $this->bookOrder($patient['id'], 'DHNPSC1', ['items' => [$this->testItem('VITD')]])->assertCreated()->json('data');
        $invoiceId = $online['invoices'][0]['id'];
        $link = $this->postJson('/api/v1/payment-links', ['invoice_id' => $invoiceId])->assertOk()->json('data');
        $this->gatewayWebhook($this->linkPaidPayload('invoice_id', $invoiceId, $link['link_id'], 140000))->assertOk();
        $this->getJson("/api/v1/orders/{$online['id']}")->assertJsonPath('data.status', 'confirmed');

        // Revenue share: nothing is charged per test.
        $this->assertSame('0.00', $this->franchiseBalance('FRDHN'));
    }

    /** @return string the client's charges for the month */
    private function runOctoberAtPatnaCityHospital(): string
    {
        $labDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATCL1')]);
        $patient = $this->actingAsStaff($labDesk)->registerPatient(['phone' => '9876509999']);

        $order = $this->bookOrder($patient['id'], 'PATCL1', [
            'order_source' => 'b2b',
            'b2b_client_id' => $this->clientId('CLPCH'),
            'items' => [$this->testItem('CBC'), $this->testItem('VITD')],
        ])->assertCreated()->assertJsonPath('data.status', 'confirmed')->json('data');

        $this->assertSame(['245.00', '980.00'], array_column($order['items'], 'partner_price'));

        return '1225.00';
    }

    /** @return array<string, mixed> */
    private function settlementOf(string $partyColumn, string $partyId): array
    {
        $list = $this->actingAsStaff($this->finance)->getJson("/api/v1/settlements?filter[{$partyColumn}]={$partyId}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data');

        return $this->actingAsStaff($this->finance)->getJson("/api/v1/settlements/{$list[0]['id']}")->assertOk()->json('data');
    }

    /**
     * @param  array<string, mixed>  $settlement
     * @param  array<string, string>  $expected
     */
    private function assertSettlement(array $settlement, array $expected): void
    {
        $actual = array_intersect_key($settlement, $expected);
        ksort($actual);
        ksort($expected);

        $this->assertSame($expected, $actual);
    }
}
