<?php

namespace Tests\Feature\Dashboards;

use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Dashboards\Jobs\BuildDailyMetrics;
use App\Modules\Dashboards\Models\DailyBranchMetric;
use App\Modules\Network\Models\Region;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Ledger\MovesMoney;
use Tests\TestCase;

/**
 * Dashboards (spec §8) from the nightly per-branch summary: each level sees
 * its own slice, and the numbers come from last night's summary only.
 */
final class DashboardTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use MovesMoney;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 11:00', 'Asia/Kolkata'));
        $this->useTestGatewaySecret();
        $this->setUpDemoNetwork();
    }

    public function test_each_level_sees_its_slice_of_the_nightly_summary(): void
    {
        // A day at a company PSC in Patna and a franchise PSC in Gaya.
        $patnaDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATPSC1')]);
        $patientId = $this->actingAsStaff($patnaDesk)->registerPatient()['id'];
        $this->bookOrder($patientId, 'PATPSC1', ['items' => [$this->testItem('CBC'), $this->testItem('LIPID')], 'payment' => ['mode' => 'cash', 'amount' => '950.00']])->assertCreated();
        $cancelled = $this->bookOrder($patientId, 'PATPSC1', ['items' => [$this->testItem('TSH')]])->assertCreated()->json('data');
        $this->postJson("/api/v1/orders/{$cancelled['id']}/cancel", ['reason' => 'Booked twice'])->assertOk();

        $this->actingAsStaff($this->staff(SystemRole::FranchiseOwner, ['franchise_id' => $this->franchiseId('FRGAYA')]))->topUpWallet('500.00');
        $gayaDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('GAYPSC1')]);
        $this->actingAsStaff($gayaDesk)->bookOrder($patientId, 'GAYPSC1', ['items' => [$this->testItem('CBC')], 'payment' => ['mode' => 'cash', 'amount' => '350.00']])->assertCreated();

        // Last night's job; running it again replaces the day rather than adding to it.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 01:30', 'Asia/Kolkata'));
        dispatch_sync(new BuildDailyMetrics);
        dispatch_sync(new BuildDailyMetrics('2026-10-05'));
        $this->assertSame(2, $this->asSystem(fn () => DailyBranchMetric::query()->count()));

        $period = '?from=2026-10-01&to=2026-10-05';
        $hq = $this->actingAsStaff($this->staff(SystemRole::SuperAdmin))->getJson("/api/v1/dashboards/hq{$period}")
            ->assertOk()
            ->assertJsonPath('data.summarised_through', '2026-10-05')
            ->assertJsonPath('data.totals.orders_booked', 3)
            ->assertJsonPath('data.totals.orders_cancelled', 1)
            ->assertJsonPath('data.totals.tests_ordered', 3)
            ->assertJsonPath('data.totals.gross_billing', '1300.00')
            ->assertJsonPath('data.totals.collected', '1300.00')
            ->assertJsonPath('data.by_day.0.date', '2026-10-05')
            ->assertJsonCount(2, 'data.by_branch')
            ->json('data');
        // Gaya's wallet is in credit after its charge; nobody owes HQ yet.
        $this->assertSame(['owed_to_hq' => '0.00', 'partners_owing' => 0, 'partner_credit_held' => '290.00'], $hq['dues']);

        // The franchise owner sees its branches and its wallet, and nothing else.
        $owner = $this->staff(SystemRole::FranchiseOwner, ['franchise_id' => $this->franchiseId('FRGAYA')]);
        $this->actingAsStaff($owner)->getJson('/api/v1/dashboards/franchise/'.$this->franchiseId('FRGAYA').$period)
            ->assertOk()
            ->assertJsonPath('data.totals.orders_booked', 1)
            ->assertJsonPath('data.totals.gross_billing', '350.00')
            ->assertJsonPath('data.dues.balance', '290.00')
            ->assertJsonPath('data.dues.billing_model', 'wholesale');
        $this->actingAsStaff($owner)->getJson('/api/v1/dashboards/franchise/'.$this->franchiseId('FRDHN'))->assertNotFound();
        $this->actingAsStaff($owner)->getJson('/api/v1/dashboards/hq')->assertForbidden();

        // A regional manager for Bihar sees Patna and Gaya, not Jharkhand.
        $bihar = $this->asSystem(fn () => (string) Region::query()->where('name', 'Bihar')->value('id'));
        $jharkhand = $this->asSystem(fn () => (string) Region::query()->where('name', 'Jharkhand')->value('id'));
        $regional = $this->staff(SystemRole::RegionalManager, ['region_id' => $bihar]);
        $this->actingAsStaff($regional)->getJson("/api/v1/dashboards/region/{$bihar}{$period}")->assertOk()->assertJsonPath('data.totals.orders_booked', 3);
        $this->actingAsStaff($regional)->getJson("/api/v1/dashboards/region/{$jharkhand}")->assertNotFound();

        // A branch admin sees its own branch only.
        $admin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->actingAsStaff($admin)->getJson('/api/v1/dashboards/branch/'.$this->branchId('PATPSC1').$period)
            ->assertOk()
            ->assertJsonPath('data.totals.orders_booked', 2)
            ->assertJsonPath('data.totals.collected', '950.00');
        $this->actingAsStaff($admin)->getJson('/api/v1/dashboards/branch/'.$this->branchId('GAYPSC1'))->assertNotFound();
        $this->actingAsStaff($admin)->getJson('/api/v1/dashboards/branch/'.$this->branchId('PATPSC1').'?from=2026-10-05&to=2026-10-01')->assertStatus(422);
    }
}
