<?php

namespace Tests\Feature\Network;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\Region;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\TestCase;

final class BranchManagementTest extends TestCase
{
    use BuildsStaff;
    use RefreshDatabase;

    private User $operations;

    private Region $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->region = $this->asSystem(fn () => Region::factory()->create(['organization_id' => $this->organization->id]));
        $this->operations = $this->staff(SystemRole::HqOperations);
        $this->actingAsStaff($this->operations);
    }

    public function test_a_new_branch_starts_in_setup_and_is_audited(): void
    {
        $response = $this->postJson('/api/v1/branches', $this->branchPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'setup')
            ->assertJsonPath('data.branch_code', 'PAT01')
            ->assertJsonPath('data.owner_type', 'company');

        $this->assertSame('/api/v1/branches/'.$response->json('data.id'), $response->headers->get('Location'));
        $this->assertTrue($this->asSystem(fn () => AuditLog::query()->where('action', 'branch.create')->exists()));
    }

    public function test_ownership_must_be_consistent(): void
    {
        $franchise = $this->asSystem(fn () => Franchise::factory()->in($this->region)->create());

        $this->postJson('/api/v1/branches', $this->branchPayload(['owner_type' => 'franchise']))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'franchise_id');

        $this->postJson('/api/v1/branches', $this->branchPayload(['franchise_id' => $franchise->id]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'franchise_id');

        $this->postJson('/api/v1/branches', $this->branchPayload(['owner_type' => 'franchise', 'franchise_id' => $franchise->id]))
            ->assertCreated()
            ->assertJsonPath('data.franchise_id', $franchise->id);
    }

    public function test_only_labs_carry_nabl_accreditation(): void
    {
        $this->postJson('/api/v1/branches', $this->branchPayload(['nabl_certificate_no' => 'MC-1234']))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'nabl_certificate_no');

        $this->postJson('/api/v1/branches', $this->branchPayload(['branch_type' => 'clinical_lab', 'nabl_certificate_no' => 'MC-1234', 'nabl_valid_till' => '2027-03-31']))
            ->assertCreated()
            ->assertJsonPath('data.nabl_valid_till', '2027-03-31');
    }

    public function test_branch_codes_are_unique_and_formatted(): void
    {
        $this->postJson('/api/v1/branches', $this->branchPayload())->assertCreated();

        $this->postJson('/api/v1/branches', $this->branchPayload())
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'branch_code');

        $this->postJson('/api/v1/branches', $this->branchPayload(['branch_code' => 'pat 02']))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'branch_code');
    }

    public function test_status_moves_only_along_the_lifecycle(): void
    {
        $branchId = $this->postJson('/api/v1/branches', $this->branchPayload())->json('data.id');

        $this->postJson("/api/v1/branches/{$branchId}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/v1/branches/{$branchId}/suspend")->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson("/api/v1/branches/{$branchId}/close")->assertOk()->assertJsonPath('data.status', 'closed');

        $this->postJson("/api/v1/branches/{$branchId}/activate")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
    }

    public function test_status_cannot_be_patched_directly(): void
    {
        $branchId = $this->postJson('/api/v1/branches', $this->branchPayload())->json('data.id');
        $etag = $this->getJson("/api/v1/branches/{$branchId}")->headers->get('ETag');

        $this->patchJson("/api/v1/branches/{$branchId}", ['status' => 'active', 'name' => 'Renamed'], ['If-Match' => $etag])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.status', 'setup');
    }

    public function test_only_unused_setup_branches_can_be_deleted(): void
    {
        $branchId = $this->postJson('/api/v1/branches', $this->branchPayload())->json('data.id');
        $this->staff(SystemRole::FrontDesk, ['branch_id' => $branchId]);

        $this->actingAsStaff($this->operations)
            ->deleteJson("/api/v1/branches/{$branchId}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BRANCH_NOT_DELETABLE');

        $emptyBranchId = $this->postJson('/api/v1/branches', $this->branchPayload(['branch_code' => 'PAT02']))->json('data.id');
        $this->deleteJson("/api/v1/branches/{$emptyBranchId}")->assertNoContent();
        $this->getJson("/api/v1/branches/{$emptyBranchId}")->assertNotFound();
    }

    public function test_regions_cannot_be_moved_under_themselves_or_deleted_while_used(): void
    {
        $child = $this->postJson('/api/v1/regions', ['name' => 'Patna', 'region_type' => 'city', 'parent_region_id' => $this->region->id])
            ->assertCreated()
            ->json('data.id');

        $etag = $this->getJson("/api/v1/regions/{$this->region->id}")
            ->assertOk()
            ->assertJsonPath('data.children.0.id', $child)
            ->headers->get('ETag');

        $this->patchJson("/api/v1/regions/{$this->region->id}", ['parent_region_id' => $child], ['If-Match' => $etag])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'parent_region_id');

        $this->deleteJson("/api/v1/regions/{$this->region->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REGION_IN_USE');

        $this->deleteJson("/api/v1/regions/{$child}")->assertNoContent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function branchPayload(array $overrides = []): array
    {
        return [
            'region_id' => $this->region->id,
            'branch_code' => 'PAT01',
            'name' => 'Patna Boring Road PSC',
            'owner_type' => 'company',
            'branch_type' => 'psc',
            'address' => 'Boring Road, Patna',
            'pincode' => '800001',
            'phone' => '9876543210',
            ...$overrides,
        ];
    }
}
