<?php

namespace Tests\Feature\Catalogue;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Catalogue\Models\LabTestCapability;
use App\Modules\Catalogue\Models\Package;
use App\Modules\Network\Enums\BranchStatus;
use App\Modules\Network\Models\B2bClient;
use App\Modules\Network\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Support\Auth\BuildsStaff;
use Tests\TestCase;

/**
 * Phase 2 "done when" (spec §12): POST /order-quotes returns the correct price
 * and processing lab for any branch. Runs against the full demo network and
 * catalogue (spec §11.6).
 */
final class OrderQuoteTest extends TestCase
{
    use BuildsStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->useSeededOrganization();
    }

    public function test_company_psc_walk_in_pays_mrp_and_specialised_tests_go_to_the_reference_lab(): void
    {
        $response = $this->quoteAs('PATPSC1', [$this->test('CBC'), $this->test('VITD')])->assertOk();

        $response->assertJsonPath('data.lines.0.mrp_price', '350.00')
            ->assertJsonPath('data.lines.0.partner_price', '0.00')
            ->assertJsonPath('data.lines.0.processing_branch_id', $this->branchId('PATCL1'))
            ->assertJsonPath('data.lines.0.container_type', 'Lavender top')
            ->assertJsonPath('data.lines.1.mrp_price', '1400.00')
            ->assertJsonPath('data.lines.1.processing_branch_id', $this->branchId('PATREF'))
            ->assertJsonPath('data.totals.mrp_total', '1750.00')
            ->assertJsonPath('data.partner_price_list_id', null);
    }

    public function test_a_branch_price_list_overrides_mrp_item_by_item(): void
    {
        $this->quoteAs('PATPSC2', [$this->test('CBC'), $this->test('GLU-F')])
            ->assertOk()
            ->assertJsonPath('data.lines.0.mrp_price', '400.00')
            ->assertJsonPath('data.lines.1.mrp_price', '100.00');
    }

    public function test_franchise_bookings_carry_the_partner_price_even_for_branch_level_staff(): void
    {
        // Regression: the franchise row is invisible at branch scope, but its
        // partner price list must still apply.
        $this->quoteAs('GAYPSC1', [$this->test('CBC'), $this->package('PKG-THY')])
            ->assertOk()
            ->assertJsonPath('data.lines.0.mrp_price', '350.00')
            ->assertJsonPath('data.lines.0.partner_price', '210.00')
            ->assertJsonPath('data.lines.0.processing_branch_id', $this->branchId('PATCL1'))
            ->assertJsonPath('data.lines.1.partner_price', '359.40')
            ->assertJsonPath('data.totals.partner_total', '569.40');
    }

    public function test_b2b_bookings_use_the_client_price_list_and_a_lab_runs_its_own_tests(): void
    {
        $clientId = $this->asSystem(fn () => B2bClient::query()->where('client_code', 'CLPCH')->value('id'));

        $this->quoteAs('PATCL1', [$this->test('CBC'), $this->test('VITD')], $clientId)
            ->assertOk()
            ->assertJsonPath('data.b2b_client_id', $clientId)
            ->assertJsonPath('data.lines.0.partner_price', '245.00')
            ->assertJsonPath('data.lines.0.processing_branch_id', $this->branchId('PATCL1'))
            ->assertJsonPath('data.lines.1.processing_branch_id', $this->branchId('PATREF'));
    }

    public function test_packages_expand_into_child_tests_routed_one_by_one(): void
    {
        $lines = $this->quoteAs('RNCPSC1', [$this->package('PKG-FULL')])
            ->assertOk()
            ->assertJsonPath('data.lines.0.line_type', 'package')
            ->assertJsonPath('data.lines.0.mrp_price', '3499.00')
            ->assertJsonPath('data.totals.mrp_total', '3499.00')
            ->json('data.lines.0.children');

        $labByCode = array_column($lines, 'processing_branch_id', 'code');
        $this->assertCount(10, $lines);
        $this->assertSame($this->branchId('RNCCL1'), $labByCode['CBC']);
        $this->assertSame($this->branchId('PATREF'), $labByCode['VITD']);
        $this->assertSame(['0.00'], array_values(array_unique(array_column($lines, 'mrp_price'))));
    }

    public function test_a_switched_off_analyser_falls_back_to_the_next_lab(): void
    {
        $this->asSystem(fn () => LabTestCapability::query()
            ->where('branch_id', $this->branchId('PATCL1'))
            ->where('test_id', $this->test('CBC')['test_id'])
            ->update(['is_active' => false]));

        $this->quoteAs('PATPSC1', [$this->test('CBC')])
            ->assertOk()
            ->assertJsonPath('data.lines.0.processing_branch_id', $this->branchId('PATREF'));
    }

    public function test_a_suspended_lab_receives_no_work(): void
    {
        $this->asSystem(fn () => Branch::query()->where('branch_code', 'PATCL1')->update(['status' => BranchStatus::Suspended]));

        $this->quoteAs('GAYPSC2', [$this->test('LIPID')])
            ->assertOk()
            ->assertJsonPath('data.lines.0.processing_branch_id', $this->branchId('PATREF'));
    }

    public function test_unpriced_and_unroutable_items_are_reported_per_item(): void
    {
        $unpriced = $this->asSystem(function () {
            $test = LabTest::query()->where('code', 'CBC')->firstOrFail()->replicate(['code']);
            $test->code = 'NEWTEST';
            $test->save();

            return $test->id;
        });

        $this->quoteAs('PATPSC1', [$this->test('CBC'), ['test_id' => $unpriced]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PRICE_MISSING')
            ->assertJsonPath('error.details.0.field', 'items.1')
            ->assertJsonPath('error.details.0.test_id', $unpriced);
    }

    public function test_staff_cannot_quote_for_a_branch_outside_their_scope(): void
    {
        $this->actingAsStaff($this->frontDeskAt('PATPSC1'))
            ->postJson('/api/v1/order-quotes', ['branch_id' => $this->branchId('GAYPSC1'), 'items' => [$this->test('CBC')]])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'branch_id');
    }

    public function test_only_booking_staff_may_quote(): void
    {
        $phlebotomist = $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('PATPSC1')]);

        $this->actingAsStaff($phlebotomist)
            ->postJson('/api/v1/order-quotes', ['branch_id' => $this->branchId('PATPSC1'), 'items' => [$this->test('CBC')]])
            ->assertForbidden();
    }

    public function test_routing_resolution_shows_the_lab_for_each_test(): void
    {
        $cbc = $this->test('CBC')['test_id'];
        $vitaminD = $this->test('VITD')['test_id'];

        $this->actingAsStaff($this->frontDeskAt('DHNPSC1'))
            ->getJson('/api/v1/routing-resolutions?'.http_build_query(['branch_id' => $this->branchId('DHNPSC1'), 'test_ids' => [$cbc, $vitaminD]]))
            ->assertOk()
            ->assertJsonPath('data.0.processing_branch_id', $this->branchId('RNCCL1'))
            ->assertJsonPath('data.1.processing_branch_id', $this->branchId('PATREF'));
    }

    /**
     * @param  list<array<string, string>>  $items
     * @return TestResponse<JsonResponse>
     */
    private function quoteAs(string $branchCode, array $items, ?string $b2bClientId = null): TestResponse
    {
        return $this->actingAsStaff($this->frontDeskAt($branchCode))->postJson('/api/v1/order-quotes', array_filter([
            'branch_id' => $this->branchId($branchCode),
            'b2b_client_id' => $b2bClientId,
            'items' => $items,
        ]));
    }

    private function frontDeskAt(string $branchCode): User
    {
        return $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId($branchCode)]);
    }

    private function branchId(string $branchCode): string
    {
        return $this->asSystem(fn () => Branch::query()->where('branch_code', $branchCode)->value('id'));
    }

    /** @return array{test_id: string} */
    private function test(string $code): array
    {
        return ['test_id' => $this->asSystem(fn () => LabTest::query()->where('code', $code)->value('id'))];
    }

    /** @return array{package_id: string} */
    private function package(string $code): array
    {
        return ['package_id' => $this->asSystem(fn () => Package::query()->where('code', $code)->value('id'))];
    }
}
