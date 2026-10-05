<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\TestCase;

/**
 * Phase 1 "done when" (spec §12): a Super Admin creates a branch and a branch
 * user who sees only that branch.
 */
final class FoundationScenarioTest extends TestCase
{
    use BuildsStaff;
    use RefreshDatabase;

    public function test_super_admin_creates_a_branch_and_a_branch_user_who_sees_only_that_branch(): void
    {
        $this->setUpOrganization();
        $region = $this->asSystem(fn () => Region::factory()->create(['organization_id' => $this->organization->id]));
        $existingBranch = $this->asSystem(fn () => Branch::factory()->in($region)->create());
        $existingColleague = $this->staff(SystemRole::FrontDesk, ['branch_id' => $existingBranch->id]);

        // Super Admin sets up a new branch and its admin.
        $this->actingAsStaff($this->staff(SystemRole::SuperAdmin));

        $branchId = $this->postJson('/api/v1/branches', [
            'region_id' => $region->id, 'branch_code' => 'NEW01', 'name' => 'New PSC', 'owner_type' => 'company',
            'branch_type' => 'psc', 'address' => 'Main Road', 'pincode' => '800001', 'phone' => '9876543210',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/branches/{$branchId}/activate")->assertOk();

        $this->postJson('/api/v1/users', [
            'role_id' => $this->asSystem(fn () => Role::query()->where('name', SystemRole::BranchAdmin->value)->value('id')),
            'branch_id' => $branchId,
            'name' => 'New Branch Admin',
            'email' => 'branch.admin@example.com',
            'phone' => '9811100000',
            'password' => 'First-day-at-work-1',
        ])->assertCreated();

        // The new branch admin signs in like any user would.
        app('auth')->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['login_identifier' => 'branch.admin@example.com', 'password' => 'First-day-at-work-1'])
            ->assertJsonPath('data.status', 'authenticated')
            ->json('data.tokens.access_token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertJsonPath('data.scope.level', 'branch')
            ->assertJsonPath('data.scope.branch_ids', [$branchId]);

        $visibleStaff = $this->withToken($token)->getJson('/api/v1/users')->assertOk()->json('data.*.email');
        $this->assertSame(['branch.admin@example.com'], $visibleStaff);

        $this->withToken($token)->getJson("/api/v1/users/{$existingColleague->id}")->assertNotFound();
    }
}
