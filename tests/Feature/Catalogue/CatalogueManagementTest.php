<?php

namespace Tests\Feature\Catalogue;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Catalogue\Models\PriceList;
use App\Modules\Network\Enums\BranchType;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\Auth\BuildsStaff;
use Tests\TestCase;

/** HQ catalogue, price list and routing management (spec §7.4, §8). */
final class CatalogueManagementTest extends TestCase
{
    use BuildsStaff;
    use RefreshDatabase;

    private User $superAdmin;

    private Branch $lab;

    private Branch $psc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->asSystem(function (): void {
            $region = Region::factory()->create(['organization_id' => $this->organization->id]);
            $this->lab = Branch::factory()->in($region)->ofType(BranchType::ClinicalLab)->create();
            $this->psc = Branch::factory()->in($region)->create();
        });
        $this->superAdmin = $this->staff(SystemRole::SuperAdmin);
        $this->actingAsStaff($this->superAdmin);
    }

    public function test_hq_builds_a_test_with_parameters_and_reference_ranges(): void
    {
        $testId = $this->createTest('HB', 'Haemoglobin');

        $this->postJson("/api/v1/tests/{$testId}/parameters", [
            'code' => 'HB',
            'parameter_name' => 'Haemoglobin',
            'unit' => 'g/dL',
            'result_type' => 'numeric',
            'decimal_places' => 1,
            'reference_ranges' => [
                ['gender' => 'male', 'age_min_days' => 6570, 'ref_low' => '13', 'ref_high' => '17', 'critical_low' => '7'],
                ['gender' => 'female', 'age_min_days' => 6570, 'ref_low' => '12', 'ref_high' => '15'],
            ],
        ])->assertCreated()->assertJsonCount(2, 'data.reference_ranges');

        $this->getJson("/api/v1/tests/{$testId}")
            ->assertOk()
            ->assertJsonPath('data.parameters.0.reference_ranges.0.gender', 'male')
            ->assertJsonPath('data.parameters.0.reference_ranges.0.ref_low', '13.0000');
    }

    public function test_option_parameters_need_options_and_calculated_ones_a_formula(): void
    {
        $testId = $this->createTest('HBSAG', 'HBsAg');

        $this->postJson("/api/v1/tests/{$testId}/parameters", ['code' => 'HBSAG', 'parameter_name' => 'HBsAg', 'result_type' => 'option'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'options');

        $this->postJson("/api/v1/tests/{$testId}/parameters", ['code' => 'LDL', 'parameter_name' => 'LDL', 'result_type' => 'calculated'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'formula');
    }

    public function test_front_desk_reads_the_catalogue_but_cannot_change_it(): void
    {
        $this->createTest('CBC', 'Complete Blood Count');
        $frontDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->psc->id]);

        $this->actingAsStaff($frontDesk)->getJson('/api/v1/tests?q=CBC')->assertOk()->assertJsonPath('data.0.code', 'CBC');
        $this->actingAsStaff($frontDesk)->postJson('/api/v1/tests', [])->assertForbidden();
        $this->actingAsStaff($frontDesk)->getJson('/api/v1/price-lists')->assertForbidden();
    }

    public function test_only_one_default_mrp_list_exists_at_a_time(): void
    {
        $first = $this->postJson('/api/v1/price-lists', ['name' => 'MRP 2026', 'list_type' => 'mrp', 'is_default_mrp' => true, 'valid_from' => '2026-04-01'])
            ->assertCreated()->json('data.id');

        $this->postJson('/api/v1/price-lists', ['name' => 'MRP 2027', 'list_type' => 'mrp', 'is_default_mrp' => true, 'valid_from' => '2027-04-01'])
            ->assertCreated()->assertJsonPath('data.is_default_mrp', true);

        $this->getJson("/api/v1/price-lists/{$first}")->assertJsonPath('data.is_default_mrp', false);

        $this->postJson('/api/v1/price-lists', ['name' => 'Partner', 'list_type' => 'partner', 'is_default_mrp' => true, 'valid_from' => '2026-04-01'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'is_default_mrp');
    }

    public function test_price_items_are_replaced_whole_and_imported_all_or_nothing(): void
    {
        $cbc = $this->createTest('CBC', 'Complete Blood Count');
        $this->createTest('ESR', 'ESR');
        $listId = $this->postJson('/api/v1/price-lists', ['name' => 'MRP', 'list_type' => 'mrp', 'valid_from' => '2026-04-01'])->json('data.id');
        $etag = $this->getJson("/api/v1/price-lists/{$listId}")->headers->get('ETag');

        $this->putJson("/api/v1/price-lists/{$listId}/items", ['items' => [['test_id' => $cbc, 'price' => '350.00']]], ['If-Match' => $etag])
            ->assertOk()
            ->assertJsonPath('data.item_count', 1);

        $badCsv = UploadedFile::fake()->createWithContent('prices.csv', "item_type,code,price\ntest,ESR,150\ntest,NOPE,10\ntest,CBC,-5\n");
        $this->post("/api/v1/price-lists/{$listId}/imports", ['file' => $badCsv], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PRICE_IMPORT_INVALID')
            ->assertJsonPath('error.details.0.field', 'row.3')
            ->assertJsonPath('error.details.1.field', 'row.4');

        $goodCsv = UploadedFile::fake()->createWithContent('prices.csv', "item_type,code,price\ntest,ESR,150\ntest,CBC,375.50\n");
        $this->post("/api/v1/price-lists/{$listId}/imports", ['file' => $goodCsv], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1);

        $prices = $this->getJson("/api/v1/price-lists/{$listId}/items")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['CBC' => '375.50', 'ESR' => '150.00'], array_column($prices, 'price', 'code'));
    }

    public function test_a_price_list_in_use_cannot_be_deleted(): void
    {
        $listId = $this->postJson('/api/v1/price-lists', ['name' => 'Branch MRP', 'list_type' => 'mrp', 'valid_from' => '2026-04-01'])->json('data.id');
        $this->asSystem(fn () => $this->psc->update(['mrp_price_list_id' => $listId]));

        $this->deleteJson("/api/v1/price-lists/{$listId}")->assertStatus(409)->assertJsonPath('error.code', 'CATALOGUE_ITEM_IN_USE');
    }

    public function test_capabilities_are_for_labs_and_routing_rules_need_one(): void
    {
        $cbc = $this->createTest('CBC', 'Complete Blood Count');

        $this->putJson("/api/v1/branches/{$this->psc->id}/capabilities", ['capabilities' => [['test_id' => $cbc]]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BRANCH_NOT_LAB');

        $this->postJson('/api/v1/routing-rules', ['source_branch_id' => $this->psc->id, 'test_id' => $cbc, 'processing_branch_id' => $this->lab->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LAB_CANNOT_RUN_TEST');

        $this->putJson("/api/v1/branches/{$this->lab->id}/capabilities", ['capabilities' => [['test_id' => $cbc, 'daily_capacity' => 500]]])
            ->assertOk()
            ->assertJsonPath('data.capability_count', 1);

        $ruleId = $this->postJson('/api/v1/routing-rules', ['source_branch_id' => $this->psc->id, 'test_id' => $cbc, 'processing_branch_id' => $this->lab->id])
            ->assertCreated()
            ->assertJsonPath('data.priority', 1)
            ->json('data.id');

        $this->getJson('/api/v1/routing-rules')->assertOk()->assertJsonPath('data.0.id', $ruleId);

        $this->postJson('/api/v1/routing-rules', ['source_branch_id' => $this->psc->id, 'processing_branch_id' => $this->psc->id])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'processing_branch_id');
    }

    public function test_a_test_on_a_price_list_cannot_be_deleted_only_deactivated(): void
    {
        $cbc = $this->createTest('CBC', 'Complete Blood Count');
        $listId = $this->postJson('/api/v1/price-lists', ['name' => 'MRP', 'list_type' => 'mrp', 'valid_from' => '2026-04-01'])->json('data.id');
        $this->asSystem(fn () => PriceList::query()->findOrFail($listId)->items()->create(['test_id' => $cbc, 'price' => '350.00']));

        $this->deleteJson("/api/v1/tests/{$cbc}")->assertStatus(409);

        $etag = $this->getJson("/api/v1/tests/{$cbc}")->headers->get('ETag');
        $this->patchJson("/api/v1/tests/{$cbc}", ['is_active' => false], ['If-Match' => $etag])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    private function createTest(string $code, string $name): string
    {
        $departmentId = $this->getJson('/api/v1/departments')->json('data.0.id')
            ?? $this->postJson('/api/v1/departments', ['name' => 'Haematology', 'signing_discipline' => 'pathology'])->assertCreated()->json('data.id');

        return $this->postJson('/api/v1/tests', [
            'department_id' => $departmentId,
            'code' => $code,
            'name' => $name,
            'sample_type' => 'EDTA whole blood',
            'container_type' => 'Lavender top',
            'tat_hours' => 6,
            'base_price' => '350.00',
        ])->assertCreated()->json('data.id');
    }
}
