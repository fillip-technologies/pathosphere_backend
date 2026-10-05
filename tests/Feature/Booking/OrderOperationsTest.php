<?php

namespace Tests\Feature\Booking;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Network\Enums\FranchiseStatus;
use App\Modules\Network\Models\B2bClient;
use App\Modules\Network\Models\Franchise;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\TestCase;

/** B2B credit, home collection, discounts, cancellation, add-on tests (spec §5.2, §5.3). */
final class OrderOperationsTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use RefreshDatabase;

    private User $frontDesk;

    private User $branchAdmin;

    private string $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoNetwork();
        $this->frontDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->branchAdmin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->actingAsStaff($this->frontDesk);
        $this->patientId = $this->registerPatient()['id'];
    }

    public function test_b2b_orders_are_billed_to_the_client_on_credit_with_a_due_date(): void
    {
        $this->actingAsStaff($this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATCL1')]));
        $client = $this->asSystem(fn () => B2bClient::query()->where('client_code', 'CLPCH')->firstOrFail());

        $this->bookOrder($this->patientId, 'PATCL1', [
            'order_source' => 'b2b',
            'b2b_client_id' => $client->id,
            'external_ref' => 'IP-2026-0042',
            'items' => [$this->testItem('CBC')],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.items.0.partner_price', '245.00')
            ->assertJsonPath('data.invoices.0.bill_to_type', 'b2b_client')
            ->assertJsonPath('data.invoices.0.payment_status', 'credit')
            ->assertJsonPath('data.invoices.0.due_date', CarbonImmutable::now('Asia/Kolkata')->addDays(30)->toDateString());
    }

    public function test_source_rules_are_checked_before_booking(): void
    {
        $this->bookOrder($this->patientId, 'PATPSC1', ['order_source' => 'b2b', 'items' => [$this->testItem('CBC')]])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'b2b_client_id');

        $this->bookOrder($this->patientId, 'PATPSC1', ['order_source' => 'online', 'items' => [$this->testItem('CBC')], 'payment' => ['mode' => 'cash', 'amount' => '350']])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'payment');

        $this->bookOrder($this->patientId, 'PATPSC1', ['order_source' => 'home_collection', 'items' => [$this->testItem('CBC')]])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'home_collection');
    }

    public function test_home_collection_is_assigned_and_collected_with_gps_proof(): void
    {
        $order = $this->bookOrder($this->patientId, 'PATPSC1', [
            'order_source' => 'home_collection',
            'items' => [$this->testItem('CBC')],
            'home_collection' => [
                'address' => '12 Boring Road, Patna',
                'pincode' => '800001',
                'slot_start' => CarbonImmutable::now()->addDay()->setTime(7, 0)->toIso8601String(),
                'slot_end' => CarbonImmutable::now()->addDay()->setTime(8, 0)->toIso8601String(),
                'collection_charge' => '100.00',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.home_collection.status', 'scheduled')
            ->assertJsonPath('data.invoices.0.total', '450.00')
            ->json('data');
        $visitId = $order['home_collection']['id'];

        $phlebotomist = $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('PATPSC1')]);
        $otherPhlebotomist = $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('PATPSC1')]);
        $elsewhere = $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('PATPSC2')]);

        $this->actingAsStaff($this->branchAdmin);
        $this->postJson("/api/v1/home-collections/{$visitId}/assign", ['phlebotomist_id' => $elsewhere->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'PHLEBOTOMIST_NOT_AVAILABLE');
        $this->postJson("/api/v1/home-collections/{$visitId}/assign", ['phlebotomist_id' => $phlebotomist->id])
            ->assertOk()->assertJsonPath('data.status', 'assigned');

        // Phlebotomists see and move only their own visits.
        $this->actingAsStaff($otherPhlebotomist)->getJson('/api/v1/home-collections')->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/home-collections/{$visitId}/status", ['status' => 'en_route'])->assertNotFound();

        $this->actingAsStaff($phlebotomist)->getJson('/api/v1/home-collections')->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/home-collections/{$visitId}/status", ['status' => 'en_route'])->assertOk();
        $this->postJson("/api/v1/home-collections/{$visitId}/status", ['status' => 'collected'])
            ->assertStatus(422)->assertJsonPath('error.code', 'GPS_REQUIRED');
        $this->postJson("/api/v1/home-collections/{$visitId}/status", ['status' => 'collected', 'latitude' => '25.611200', 'longitude' => '85.144000'])
            ->assertOk()->assertJsonPath('data.status', 'collected');
        $this->postJson("/api/v1/home-collections/{$visitId}/status", ['status' => 'en_route'])
            ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
    }

    public function test_discounts_are_spread_over_lines_and_large_ones_need_approval(): void
    {
        $items = [$this->testItem('CBC'), $this->testItem('LIPID')];

        $this->bookOrder($this->patientId, 'PATPSC1', ['items' => $items, 'discount' => ['amount' => '200.00', 'reason' => 'Camp offer']])
            ->assertStatus(403)->assertJsonPath('error.code', 'DISCOUNT_NEEDS_APPROVAL');

        $order = $this->bookOrder($this->patientId, 'PATPSC1', ['items' => $items, 'discount' => ['amount' => '95.00', 'reason' => 'Senior citizen'], 'payment' => ['mode' => 'cash', 'amount' => '855.00']])
            ->assertCreated()
            ->assertJsonPath('data.invoices.0.amount', '950.00')
            ->assertJsonPath('data.invoices.0.discount', '95.00')
            ->assertJsonPath('data.invoices.0.total', '855.00')
            ->json('data');

        $this->assertSame(['35.00', '60.00'], array_column($order['items'], 'discount'));
        $this->assertSame(['315.00', '540.00'], array_column($order['items'], 'net_price'));

        $this->actingAsStaff($this->branchAdmin);
        $this->bookOrder($this->patientId, 'PATPSC1', ['items' => $items, 'discount' => ['amount' => '200.00', 'reason' => 'Camp offer'], 'payment' => ['mode' => 'cash', 'amount' => '750.00']])
            ->assertCreated();
    }

    public function test_cancelling_a_paid_order_refunds_it_and_needs_refund_approval(): void
    {
        $order = $this->bookOrder($this->patientId, 'PATPSC1', ['items' => [$this->testItem('CBC')], 'payment' => ['mode' => 'cash', 'amount' => '350.00']])->json('data');

        $this->postJson("/api/v1/orders/{$order['id']}/cancel", ['reason' => 'Patient left'])
            ->assertStatus(403)->assertJsonPath('error.code', 'REFUND_APPROVAL_REQUIRED');

        $this->actingAsStaff($this->branchAdmin)->postJson("/api/v1/orders/{$order['id']}/cancel", ['reason' => 'Patient left'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.items.0.status', 'cancelled')
            ->assertJsonPath('data.invoices.0.payment_status', 'refunded')
            ->assertJsonPath('data.invoices.0.payments.0.refunds.0.amount', '350.00');

        $this->postJson("/api/v1/orders/{$order['id']}/cancel", ['reason' => 'again'])->assertStatus(409);
    }

    public function test_add_on_tests_get_a_supplementary_invoice_and_cannot_repeat_a_test(): void
    {
        $order = $this->bookOrder($this->patientId, 'PATPSC1', ['items' => [$this->testItem('CBC')], 'payment' => ['mode' => 'cash', 'amount' => '350.00']])->json('data');

        $this->postJson("/api/v1/orders/{$order['id']}/items", ['items' => [$this->testItem('CBC')]])
            ->assertStatus(422)->assertJsonPath('error.code', 'DUPLICATE_TEST');

        $this->postJson("/api/v1/orders/{$order['id']}/items", ['items' => [$this->testItem('ESR')], 'payment' => ['mode' => 'upi', 'amount' => '150.00']])
            ->assertCreated()
            ->assertJsonPath('data.total', '150.00')
            ->assertJsonPath('data.payment_status', 'paid');

        $this->getJson("/api/v1/orders/{$order['id']}")->assertJsonCount(2, 'data.invoices')->assertJsonCount(2, 'data.items');
    }

    public function test_refunds_cannot_exceed_what_was_paid(): void
    {
        $order = $this->bookOrder($this->patientId, 'PATPSC1', ['items' => [$this->testItem('CBC')], 'payment' => ['mode' => 'cash', 'amount' => '350.00']])->json('data');
        $paymentId = $order['invoices'][0]['payments'][0]['id'];

        $this->actingAsStaff($this->branchAdmin);
        $this->postJson("/api/v1/payments/{$paymentId}/refunds", ['amount' => '100.00', 'reason' => 'Test not done'])->assertCreated();
        $this->postJson("/api/v1/payments/{$paymentId}/refunds", ['amount' => '300.00', 'reason' => 'Again'])
            ->assertStatus(422)->assertJsonPath('error.code', 'REFUND_EXCEEDS_PAYMENT');

        $this->getJson("/api/v1/invoices/{$order['invoices'][0]['id']}")
            ->assertJsonPath('data.amount_paid', '250.00')
            ->assertJsonPath('data.payment_status', 'partially_refunded');
    }

    public function test_a_suspended_franchise_cannot_take_orders(): void
    {
        $this->asSystem(fn () => Franchise::query()->where('franchise_code', 'FRGAYA')->update(['status' => FranchiseStatus::Suspended]));
        $this->actingAsStaff($this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('GAYPSC1')]));

        $this->bookOrder($this->patientId, 'GAYPSC1', ['items' => [$this->testItem('CBC')]])
            ->assertStatus(422)->assertJsonPath('error.code', 'FRANCHISE_SUSPENDED');
    }

    public function test_orders_are_scoped_to_the_booking_branch(): void
    {
        $order = $this->bookOrder($this->patientId, 'PATPSC1', ['items' => [$this->testItem('CBC')], 'payment' => ['mode' => 'cash', 'amount' => '350.00']])->json('data');

        $this->actingAsStaff($this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATPSC2')]))
            ->getJson("/api/v1/orders/{$order['id']}")->assertNotFound();
        $this->getJson("/api/v1/invoices/{$order['invoices'][0]['id']}")->assertNotFound();

        // The processing lab tests the sample but never sees the invoice (spec §4).
        $this->actingAsStaff($this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATCL1')]))
            ->getJson("/api/v1/invoices/{$order['invoices'][0]['id']}")->assertNotFound();
    }
}
