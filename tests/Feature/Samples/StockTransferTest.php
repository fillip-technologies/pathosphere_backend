<?php

namespace Tests\Feature\Samples;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Ledger\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Ledger\MovesMoney;
use Tests\TestCase;

/**
 * Branch stock and supply (spec §7.7): HQ's reference lab sends tubes to a
 * franchise PSC; stock leaves on dispatch, lands on receipt, and the
 * franchise is charged for the kits (spec §7.8).
 */
final class StockTransferTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use MovesMoney;
    use RefreshDatabase;

    private User $hqLabAdmin;

    private User $franchiseAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoNetwork();
        $this->hqLabAdmin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('PATREF')]);
        $this->franchiseAdmin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('GAYPSC1')]);
    }

    public function test_tubes_go_from_the_reference_lab_to_a_franchise_psc_and_are_charged_on_receipt(): void
    {
        $stock = $this->actingAsStaff($this->hqLabAdmin)->postJson('/api/v1/inventory-items', [
            'branch_id' => $this->branchId('PATREF'), 'item_code' => 'SST-5ML', 'name' => 'Serum separator tube 5 ml',
            'category' => 'tube', 'unit' => 'box', 'quantity' => '12.5', 'reorder_level' => '10', 'batch_no' => 'L77', 'expiry_date' => '2027-06-30',
        ])->assertCreated()->assertJsonPath('data.quantity', '12.50')->json('data');
        $this->postJson('/api/v1/inventory-items', ['branch_id' => $this->branchId('PATREF'), 'item_code' => 'SST-5ML', 'name' => 'x', 'category' => 'tube', 'unit' => 'box', 'quantity' => '1', 'batch_no' => 'L77'])
            ->assertStatus(409)->assertJsonPath('error.code', 'INVENTORY_ITEM_EXISTS');

        // The franchise asks for tubes; it cannot price them itself.
        $request = ['from_branch_id' => $this->branchId('PATREF'), 'to_branch_id' => $this->branchId('GAYPSC1'), 'item_code' => 'SST-5ML', 'batch_no' => 'L77', 'quantity' => '5'];
        $this->actingAsStaff($this->franchiseAdmin)->postJson('/api/v1/stock-transfers', $request + ['charge_amount' => '1.00'])
            ->assertStatus(422)->assertJsonPath('error.code', 'STOCK_CHARGE_NOT_ALLOWED');
        $transfer = $this->actingAsStaff($this->franchiseAdmin)->postJson('/api/v1/stock-transfers', $request)
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.item_name', 'Serum separator tube 5 ml')
            ->assertJsonPath('data.charge_amount', '0.00')
            ->json('data');
        $this->assertSame('ST-PATREF-000001', $transfer['transfer_no']);

        // Only the sender ships, and only what it has.
        $this->actingAsStaff($this->franchiseAdmin)->postJson("/api/v1/stock-transfers/{$transfer['id']}/dispatch")->assertForbidden();
        $etag = $this->actingAsStaff($this->hqLabAdmin)->getJson("/api/v1/stock-transfers/{$transfer['id']}")->headers->get('ETag');
        $this->patchJson("/api/v1/stock-transfers/{$transfer['id']}", ['quantity' => '20'], ['If-Match' => (string) $etag])->assertOk();
        $this->postJson("/api/v1/stock-transfers/{$transfer['id']}/dispatch")->assertStatus(409)->assertJsonPath('error.code', 'STOCK_INSUFFICIENT');
        $etag = $this->getJson("/api/v1/stock-transfers/{$transfer['id']}")->headers->get('ETag');
        $this->patchJson("/api/v1/stock-transfers/{$transfer['id']}", ['quantity' => '5'], ['If-Match' => (string) $etag])->assertOk();
        $this->postJson("/api/v1/stock-transfers/{$transfer['id']}/dispatch", ['charge_amount' => '300.00'])
            ->assertOk()
            ->assertJsonPath('data.status', 'dispatched')
            ->assertJsonPath('data.charge_amount', '300.00');
        $this->getJson("/api/v1/inventory-items/{$stock['id']}")->assertJsonPath('data.quantity', '7.50')->assertJsonPath('data.below_reorder_level', true);
        $this->assertStringContainsString('below its reorder level', $this->messages->sent[array_key_last($this->messages->sent)]['body']);

        // Another franchise sees nothing of it.
        $dhanbadAdmin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('DHNPSC1')]);
        $this->actingAsStaff($dhanbadAdmin)->getJson("/api/v1/stock-transfers/{$transfer['id']}")->assertNotFound();

        // Receipt puts the batch on the PSC's shelf and debits the franchise.
        $this->actingAsStaff($this->franchiseAdmin)->postJson("/api/v1/stock-transfers/{$transfer['id']}/receive")->assertOk()->assertJsonPath('data.status', 'received');
        $this->actingAsStaff($this->franchiseAdmin)->getJson('/api/v1/inventory-items?filter[item_code]=SST-5ML')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.branch_id', $this->branchId('GAYPSC1'))
            ->assertJsonPath('data.0.quantity', '5.00')
            ->assertJsonPath('data.0.expiry_date', '2027-06-30');
        $this->assertSame('-300.00', $this->franchiseBalance('FRGAYA'));
        $this->assertSame(1, $this->asSystem(fn () => LedgerEntry::query()->where('entry_type', 'kit_supply')->where('reference_id', $transfer['id'])->count()));
        $this->actingAsStaff($this->franchiseAdmin)->postJson("/api/v1/stock-transfers/{$transfer['id']}/receive")->assertStatus(409);
        $this->assertSame('-300.00', $this->franchiseBalance('FRGAYA'));
    }

    public function test_cancelling_a_dispatched_transfer_puts_the_stock_back(): void
    {
        $stock = $this->actingAsStaff($this->hqLabAdmin)->postJson('/api/v1/inventory-items', [
            'branch_id' => $this->branchId('PATREF'), 'item_code' => 'EDTA-3ML', 'name' => 'EDTA tube 3 ml',
            'category' => 'tube', 'unit' => 'box', 'quantity' => '10', 'batch_no' => 'B1',
        ])->json('data');
        $transfer = $this->postJson('/api/v1/stock-transfers', [
            'from_branch_id' => $this->branchId('PATREF'), 'to_branch_id' => $this->branchId('PATCL1'),
            'item_code' => 'EDTA-3ML', 'batch_no' => 'B1', 'quantity' => '4',
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/stock-transfers/{$transfer['id']}/dispatch")->assertOk();
        $this->getJson("/api/v1/inventory-items/{$stock['id']}")->assertJsonPath('data.quantity', '6.00');

        $this->postJson("/api/v1/stock-transfers/{$transfer['id']}/cancel", ['reason' => 'Courier lost the box'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson("/api/v1/inventory-items/{$stock['id']}")->assertJsonPath('data.quantity', '10.00');

        // Charges are only for franchise branches, and stock is removed only when empty.
        $this->postJson('/api/v1/stock-transfers', [
            'from_branch_id' => $this->branchId('PATREF'), 'to_branch_id' => $this->branchId('PATCL1'),
            'item_code' => 'EDTA-3ML', 'batch_no' => 'B1', 'quantity' => '1', 'charge_amount' => '50',
        ])->assertStatus(422)->assertJsonPath('error.code', 'STOCK_CHARGE_NOT_ALLOWED');
        $this->deleteJson("/api/v1/inventory-items/{$stock['id']}")->assertStatus(409)->assertJsonPath('error.code', 'INVENTORY_ITEM_IN_STOCK');
        $etag = $this->getJson("/api/v1/inventory-items/{$stock['id']}")->headers->get('ETag');
        $this->patchJson("/api/v1/inventory-items/{$stock['id']}", ['quantity' => '0'], ['If-Match' => (string) $etag])->assertOk();
        $this->deleteJson("/api/v1/inventory-items/{$stock['id']}")->assertNoContent();

        // Desk staff do not manage stock.
        $this->actingAsStaff($this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATREF')]))->getJson('/api/v1/inventory-items')->assertForbidden();
    }
}
