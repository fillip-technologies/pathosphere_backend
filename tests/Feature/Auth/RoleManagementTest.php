<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\TestCase;

/** Custom roles: never above their scope, system roles untouchable (spec §4). */
final class RoleManagementTest extends TestCase
{
    use BuildsStaff;
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->superAdmin = $this->staff(SystemRole::SuperAdmin);
        $this->actingAsStaff($this->superAdmin);
    }

    public function test_a_custom_role_gets_permissions_that_fit_its_scope(): void
    {
        $roleId = $this->postJson('/api/v1/roles', ['name' => 'Senior Front Desk', 'scope_level' => 'branch'])
            ->assertCreated()
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.permissions', [])
            ->json('data.id');

        $etag = $this->getJson("/api/v1/roles/{$roleId}")->headers->get('ETag');

        $this->putJson("/api/v1/roles/{$roleId}/permissions", ['permissions' => ['create_order', 'collect_payment']], ['If-Match' => $etag])
            ->assertOk()
            ->assertJsonPath('data.permissions', ['create_order', 'collect_payment'])
            ->assertJsonPath('data.requires_mfa', false);
    }

    public function test_a_branch_role_can_never_hold_head_office_permissions(): void
    {
        $roleId = $this->postJson('/api/v1/roles', ['name' => 'Rogue', 'scope_level' => 'branch'])->json('data.id');
        $etag = $this->getJson("/api/v1/roles/{$roleId}")->headers->get('ETag');

        $this->putJson("/api/v1/roles/{$roleId}/permissions", ['permissions' => ['create_order', 'approve_settlement']], ['If-Match' => $etag])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PERMISSION_ABOVE_SCOPE')
            ->assertJsonPath('error.details.0.permission', 'approve_settlement');
    }

    public function test_unknown_permissions_are_a_validation_error(): void
    {
        $roleId = $this->postJson('/api/v1/roles', ['name' => 'Typo', 'scope_level' => 'branch'])->json('data.id');
        $etag = $this->getJson("/api/v1/roles/{$roleId}")->headers->get('ETag');

        $this->putJson("/api/v1/roles/{$roleId}/permissions", ['permissions' => ['create_ordr']], ['If-Match' => $etag])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'permissions');
    }

    public function test_system_roles_are_read_only(): void
    {
        $frontDesk = $this->asSystem(fn () => Role::query()->where('name', 'Front Desk')->firstOrFail());
        $etag = $this->getJson("/api/v1/roles/{$frontDesk->id}")->assertOk()->headers->get('ETag');

        $this->patchJson("/api/v1/roles/{$frontDesk->id}", ['description' => 'changed'], ['If-Match' => $etag])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SYSTEM_ROLE_READ_ONLY');

        $this->deleteJson("/api/v1/roles/{$frontDesk->id}")->assertStatus(422);

        $this->postJson('/api/v1/roles', ['name' => 'front desk', 'scope_level' => 'branch'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'name');
    }

    public function test_a_role_held_by_staff_cannot_be_deleted(): void
    {
        $roleId = $this->postJson('/api/v1/roles', ['name' => 'Night Shift', 'scope_level' => 'branch'])->json('data.id');
        $branch = $this->asSystem(fn () => Branch::factory()->in(Region::factory()->create(['organization_id' => $this->organization->id]))->create());
        $this->staff($this->asSystem(fn () => Role::query()->findOrFail($roleId)), ['branch_id' => $branch->id]);

        $this->actingAsStaff($this->superAdmin)
            ->deleteJson("/api/v1/roles/{$roleId}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ROLE_IN_USE');
    }

    public function test_the_permission_catalogue_lists_scope_and_mfa_rules(): void
    {
        $this->getJson('/api/v1/permissions')
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'approve_settlement',
                'module' => 'ledger',
                'description' => 'Approve settlement.',
                'allowed_scope_levels' => ['organization'],
                'requires_mfa' => true,
            ]);
    }
}
