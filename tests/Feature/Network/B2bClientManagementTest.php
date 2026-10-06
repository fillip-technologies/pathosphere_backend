<?php

namespace Tests\Feature\Network;

use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Catalogue\Enums\PriceListType;
use App\Modules\Catalogue\Models\PriceList;
use App\Modules\Network\Models\B2bClient;
use App\Modules\Network\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\TestCase;

/** B2B clients (spec §8 B2B): management, scope, and on-hold clients cannot book. */
final class B2bClientManagementTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoNetwork();
    }

    public function test_head_office_manages_clients_and_each_side_sees_only_its_own(): void
    {
        $admin = $this->staff(SystemRole::SuperAdmin);
        $payload = [
            'client_code' => 'CLAIIMS',
            'client_type' => 'hospital',
            'name' => 'AIIMS Patna',
            'contact_name' => 'Purchase Officer',
            'phone' => '9811133344',
            'email' => 'labs@aiims.example',
            'billing_address' => 'Phulwari Sharif, Patna',
            'region_id' => $this->asSystem(fn () => (string) Region::query()->where('name', 'Patna')->value('id')),
            'serviced_by_branch_id' => $this->branchId('PATREF'),
            'price_list_id' => $this->priceListId(PriceListType::Mrp),
            'credit_limit' => '50000',
            'credit_days' => 45,
        ];

        $this->actingAsStaff($admin)->postJson('/api/v1/b2b-clients', $payload)->assertStatus(422)->assertJsonPath('error.details.0.field', 'price_list_id');
        $client = $this->actingAsStaff($admin)->postJson('/api/v1/b2b-clients', ['price_list_id' => $this->priceListId(PriceListType::Client)] + $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.credit_limit', '50000.00')
            ->assertJsonPath('data.current_balance', '0.00')
            ->json('data');

        // Clients are closed, never deleted.
        $this->actingAsStaff($admin)->deleteJson("/api/v1/b2b-clients/{$client['id']}")->assertStatus(405);

        // The servicing lab's desk sees its own clients only; the client's user sees itself.
        $referenceDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATREF')]);
        $this->actingAsStaff($referenceDesk)->getJson('/api/v1/b2b-clients')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.client_code', 'CLAIIMS');
        $this->actingAsStaff($referenceDesk)->postJson("/api/v1/b2b-clients/{$client['id']}/hold")->assertForbidden();
        $clientUser = $this->staff(SystemRole::B2bClientUser, ['b2b_client_id' => $client['id']]);
        $this->actingAsStaff($clientUser)->getJson('/api/v1/b2b-clients')->assertOk()->assertJsonCount(1, 'data');
        $other = $this->asSystem(fn () => (string) B2bClient::query()->where('client_code', 'CLPCH')->value('id'));
        $this->actingAsStaff($clientUser)->getJson("/api/v1/b2b-clients/{$other}")->assertNotFound();
    }

    public function test_a_client_on_hold_cannot_book_until_it_is_reactivated(): void
    {
        $admin = $this->staff(SystemRole::SuperAdmin);
        $clientId = $this->asSystem(fn () => (string) B2bClient::query()->where('client_code', 'CLPCH')->value('id'));
        $labDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATCL1')]);
        $patientId = $this->actingAsStaff($labDesk)->registerPatient()['id'];
        $book = fn () => $this->actingAsStaff($labDesk)->bookOrder($patientId, 'PATCL1', [
            'order_source' => 'b2b', 'b2b_client_id' => $clientId, 'items' => [$this->testItem('CBC')],
        ]);

        $this->actingAsStaff($admin)->postJson("/api/v1/b2b-clients/{$clientId}/hold")->assertOk()->assertJsonPath('data.status', 'on_hold');
        $book()->assertStatus(422)->assertJsonPath('error.code', 'B2B_CLIENT_ON_HOLD');

        $this->actingAsStaff($admin)->postJson("/api/v1/b2b-clients/{$clientId}/activate")->assertOk();
        $book()->assertCreated();

        $this->actingAsStaff($admin)->postJson("/api/v1/b2b-clients/{$clientId}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->actingAsStaff($admin)->postJson("/api/v1/b2b-clients/{$clientId}/activate")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
    }

    private function priceListId(PriceListType $type): string
    {
        return $this->asSystem(fn () => (string) PriceList::query()->where('list_type', $type)->value('id'));
    }
}
