<?php

namespace Tests\Feature\Ledger;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Booking\Models\Order;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Network\Models\FranchiseAgreement;
use App\Modules\Network\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Ledger\MovesMoney;
use Tests\Support\Samples\MovesSamples;
use Tests\TestCase;

/**
 * Partner charges on the demo network (spec §5.6, §7.8): the wholesale
 * wallet, reversals, add-on tests, revenue share, B2B credit, the quote's
 * wallet check, manual adjustments and who may see the ledger.
 */
final class PartnerChargesTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use MovesMoney;
    use MovesSamples;
    use RefreshDatabase;

    private User $gayaDesk;

    private User $gayaOwner;

    private string $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTestGatewaySecret();
        $this->setUpDemoNetwork();

        $this->gayaDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('GAYPSC1')]);
        $this->gayaOwner = $this->staff(SystemRole::FranchiseOwner, ['franchise_id' => $this->franchiseId('FRGAYA')]);
        $this->patientId = $this->actingAsStaff($this->gayaDesk)->registerPatient()['id'];
    }

    public function test_a_wholesale_booking_is_refused_when_the_wallet_cannot_pay_and_nothing_is_saved(): void
    {
        $this->actingAsStaff($this->gayaDesk)->bookOrder($this->patientId, 'GAYPSC1', [
            'items' => [$this->testItem('CBC')],
            'payment' => ['mode' => 'cash', 'amount' => '350.00'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WALLET_INSUFFICIENT')
            ->assertJsonPath('error.details.0.available', '0.00')
            ->assertJsonPath('error.details.0.required', '210.00');

        $this->assertSame(0, $this->asSystem(fn () => Order::query()->count()));
    }

    public function test_the_quote_shows_whether_the_wallet_covers_the_booking(): void
    {
        $quote = fn () => $this->actingAsStaff($this->gayaDesk)->postJson('/api/v1/order-quotes', [
            'branch_id' => $this->branchId('GAYPSC1'),
            'items' => [$this->testItem('CBC'), $this->testItem('LIPID')],
        ])->assertOk();

        $quote()->assertJsonPath('data.wallet_check.sufficient', false)->assertJsonPath('data.wallet_check.required', '570.00');

        $this->actingAsStaff($this->gayaOwner)->topUpWallet('600.00');
        $quote()
            ->assertJsonPath('data.wallet_check.balance', '600.00')
            ->assertJsonPath('data.wallet_check.available', '600.00')
            ->assertJsonPath('data.wallet_check.sufficient', true);

        // Company and revenue-share bookings are not paid from a wallet.
        $this->actingAsStaff($this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('DHNPSC1')]))
            ->postJson('/api/v1/order-quotes', ['branch_id' => $this->branchId('DHNPSC1'), 'items' => [$this->testItem('CBC')]])
            ->assertOk()
            ->assertJsonPath('data.wallet_check', null);
    }

    public function test_the_credit_limit_lets_a_wallet_go_negative_but_no_further(): void
    {
        $this->setGayaCreditLimit('300.00');

        $this->bookCash(['CBC'], '350.00')->assertCreated();
        $this->assertSame('-210.00', $this->franchiseBalance('FRGAYA'));

        $this->bookCash(['TSH'], '350.00')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WALLET_INSUFFICIENT')
            ->assertJsonPath('error.details.0.available', '90.00');
    }

    public function test_a_top_up_is_credited_once_however_often_the_gateway_repeats_it(): void
    {
        $topup = $this->actingAsStaff($this->gayaOwner)->postJson('/api/v1/wallet/topups', ['amount' => '1000.00'])
            ->assertCreated()
            ->assertJsonPath('data.franchise_id', $this->franchiseId('FRGAYA'))
            ->json('data');
        $this->assertStringStartsWith('https://pay.example.test/', $topup['payment_url']);

        $payload = $this->linkPaidPayload('wallet_topup_id', $topup['id'], $topup['payment_link_id'], 100000);
        $this->gatewayWebhook($payload, 'evt_topup')->assertOk();
        $this->gatewayWebhook($payload, 'evt_topup')->assertOk()->assertJsonPath('data.duplicate', true);
        $this->gatewayWebhook($payload, 'evt_topup_resent')->assertOk();

        $this->assertSame('1000.00', $this->franchiseBalance('FRGAYA'));
        $this->actingAsStaff($this->gayaOwner)->getJson("/api/v1/wallet/topups/{$topup['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonMissingPath('data.payment_url');
    }

    public function test_cancelling_reverses_the_charge_and_add_on_tests_are_charged(): void
    {
        $this->actingAsStaff($this->gayaOwner)->topUpWallet('1000.00');
        $order = $this->bookCash(['CBC'], '350.00')->assertCreated()->json('data');
        $this->assertSame('790.00', $this->franchiseBalance('FRGAYA'));

        $this->actingAsStaff($this->gayaDesk)->postJson("/api/v1/orders/{$order['id']}/items", [
            'items' => [$this->testItem('TSH')],
            'payment' => ['mode' => 'cash', 'amount' => '350.00'],
        ])->assertCreated();
        $this->assertSame('580.00', $this->franchiseBalance('FRGAYA'));

        $admin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('GAYPSC1')]);
        $this->actingAsStaff($admin)->postJson("/api/v1/orders/{$order['id']}/cancel", ['reason' => 'Doctor changed the tests'])->assertOk();

        $this->assertSame('1000.00', $this->franchiseBalance('FRGAYA'));
        $this->assertSame(
            ['refund_reversal', 'refund_reversal'],
            $this->asSystem(fn () => LedgerEntry::query()->where('entry_type', 'refund_reversal')->pluck('entry_type')->map->value->all()),
        );
        $this->assertLedgerAddsUp('franchise_id', $this->franchiseId('FRGAYA'), '1000.00');
    }

    public function test_a_rejected_sample_is_reversed_only_when_the_organization_says_so(): void
    {
        $this->actingAsStaff($this->gayaOwner)->topUpWallet('1000.00');
        $phlebotomist = $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('GAYPSC1')]);
        $runner = $this->staff(SystemRole::LogisticsRunner, ['branch_id' => $this->branchId('GAYPSC1')]);
        $technician = $this->staff(SystemRole::LabTechnician, ['branch_id' => $this->branchId('PATCL1')]);

        $rejectFirstSample = function () use ($phlebotomist, $runner, $technician): void {
            $order = $this->bookPaidOrder($this->gayaDesk, $this->patientId, 'GAYPSC1', ['CBC'], '350.00');
            $sample = $this->samplesByTest($this->gayaDesk, $order['id'])['CBC'];
            $this->collectSample($phlebotomist, $sample['id'])->assertOk();
            $manifest = $this->openManifestId($runner, 'GAYPSC1', 'PATCL1');
            $this->actingAsStaff($runner)->postJson("/api/v1/manifests/{$manifest}/dispatch", ['courier_name' => 'Runner'])->assertOk();
            $this->receiveManifest($technician, $manifest, [['barcode' => $sample['barcode'], 'condition' => 'rejected', 'rejection_reason' => 'haemolysed']])->assertOk();
        };

        // By default the free redraw means the partner pays once.
        $rejectFirstSample();
        $this->assertSame('790.00', $this->franchiseBalance('FRGAYA'));

        // With the policy on, the next order's 210 is charged and then credited back.
        $this->asSystem(fn () => Organization::query()->firstOrFail()->update(['settings' => ['reverse_partner_charge_on_rejection' => true]]));
        $rejectFirstSample();
        $this->assertSame('790.00', $this->franchiseBalance('FRGAYA'));
        $this->assertLedgerAddsUp('franchise_id', $this->franchiseId('FRGAYA'), '790.00');
    }

    public function test_revenue_share_and_b2b_bookings_post_by_their_own_rules(): void
    {
        $dhanbadDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('DHNPSC1')]);
        $this->actingAsStaff($dhanbadDesk)->bookOrder($this->patientId, 'DHNPSC1', [
            'items' => [$this->testItem('CBC')],
            'payment' => ['mode' => 'cash', 'amount' => '350.00'],
        ])->assertCreated()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame('0.00', $this->franchiseBalance('FRDHN'));

        // B2B is on credit: no wallet check, the client's account goes negative.
        $labDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATCL1')]);
        $this->actingAsStaff($labDesk)->bookOrder($this->patientId, 'PATCL1', [
            'order_source' => 'b2b',
            'b2b_client_id' => $this->clientId('CLPCH'),
            'items' => [$this->testItem('CBC')],
        ])->assertCreated();
        $this->assertLedgerAddsUp('b2b_client_id', $this->clientId('CLPCH'), '-245.00');
    }

    public function test_a_franchise_without_an_agreement_in_force_cannot_take_orders(): void
    {
        $this->asSystem(fn () => FranchiseAgreement::query()
            ->where('franchise_id', $this->franchiseId('FRGAYA'))
            ->update(['status' => 'expired']));

        $this->bookCash(['CBC'], '350.00')->assertStatus(422)->assertJsonPath('error.code', 'FRANCHISE_AGREEMENT_INACTIVE');
    }

    public function test_hq_finance_posts_adjustments_and_partners_see_only_their_own_ledger(): void
    {
        $finance = $this->staff(SystemRole::HqFinance);
        $adjustment = $this->actingAsStaff($finance)->postJson('/api/v1/ledger/adjustments', [
            'franchise_id' => $this->franchiseId('FRGAYA'),
            'side' => 'credit',
            'amount' => '150.00',
            'reason' => 'goodwill',
            'note' => 'Analyser downtime',
        ], ['Idempotency-Key' => 'adj-1'])
            ->assertCreated()
            ->assertJsonPath('data.entry_type', 'adjustment')
            ->assertJsonPath('data.narration', 'Adjustment: Goodwill credit (Analyser downtime)')
            ->assertJsonPath('data.balance_after', '150.00')
            ->json('data');
        $this->assertSame('150.00', $this->franchiseBalance('FRGAYA'));

        $this->actingAsStaff($finance)->postJson('/api/v1/ledger/adjustments', [
            'franchise_id' => $this->franchiseId('FRGAYA'), 'b2b_client_id' => $this->clientId('CLPCH'),
            'side' => 'debit', 'amount' => '10', 'reason' => 'goodwill',
        ], ['Idempotency-Key' => 'adj-2'])->assertStatus(422);
        $this->actingAsStaff($this->gayaOwner)->postJson('/api/v1/ledger/adjustments', [
            'franchise_id' => $this->franchiseId('FRGAYA'), 'side' => 'credit', 'amount' => '10', 'reason' => 'goodwill',
        ], ['Idempotency-Key' => 'adj-3'])->assertForbidden();

        // The franchise owner sees its own rows (fee, deposit, adjustment), not other partners'.
        $this->actingAsStaff($this->gayaOwner)->getJson('/api/v1/ledger')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAsStaff($this->gayaOwner)->getJson("/api/v1/ledger/{$adjustment['id']}")->assertOk();
        $this->actingAsStaff($this->gayaOwner)->getJson('/api/v1/ledger?filter[franchise_id]='.$this->franchiseId('FRDHN'))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsStaff($this->staff(SystemRole::FranchiseOwner, ['franchise_id' => $this->franchiseId('FRDHN')]))
            ->getJson("/api/v1/ledger/{$adjustment['id']}")->assertNotFound();

        // Branch staff, even at the franchise's own branch, never see the ledger.
        $this->actingAsStaff($this->gayaDesk)->getJson('/api/v1/ledger')->assertForbidden();
        $admin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('GAYPSC1')]);
        $this->actingAsStaff($admin)->getJson('/api/v1/settlements')->assertForbidden();
    }

    /**
     * @param  list<string>  $testCodes
     * @return TestResponse<JsonResponse>
     */
    private function bookCash(array $testCodes, string $amount): TestResponse
    {
        return $this->actingAsStaff($this->gayaDesk)->bookOrder($this->patientId, 'GAYPSC1', [
            'items' => array_map(fn (string $code) => $this->testItem($code), $testCodes),
            'payment' => ['mode' => 'cash', 'amount' => $amount],
        ]);
    }

    private function setGayaCreditLimit(string $limit): void
    {
        $finance = $this->staff(SystemRole::SuperAdmin);
        $franchiseId = $this->franchiseId('FRGAYA');
        $etag = $this->actingAsStaff($finance)->getJson("/api/v1/franchises/{$franchiseId}")->assertOk()->headers->get('ETag');

        $this->actingAsStaff($finance)->patchJson("/api/v1/franchises/{$franchiseId}", ['credit_limit' => $limit], ['If-Match' => (string) $etag])
            ->assertOk()
            ->assertJsonPath('data.credit_limit', $limit);
    }
}
