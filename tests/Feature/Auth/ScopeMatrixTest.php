<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\PermissionEntry;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\Permission;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Network\Models\B2bClient;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\Region;
use App\Modules\Shared\Scoping\ScopeLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\TestCase;

/**
 * The guard MySQL cannot give us (spec §4, §11.2): for each scope level a user
 * sees exactly the rows they should, and gets 404 (not 403) on rows outside
 * their scope.
 *
 * Network:  East (state) ─ East City (city) ─ B1 (company PSC)
 *           East ─ Franchise F ─ FB (franchise PSC)
 *           West (state) ─ B2 (company PSC)
 *           B2B client C, serviced by B1
 */
final class ScopeMatrixTest extends TestCase
{
    use BuildsStaff;
    use RefreshDatabase;

    private Branch $b1;

    private Branch $b2;

    private Branch $franchiseBranch;

    private Region $east;

    private Region $west;

    private Franchise $franchise;

    private B2bClient $client;

    /** @var array<string, User> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->asSystem(function (): void {
            $this->east = Region::factory()->create(['organization_id' => $this->organization->id, 'name' => 'East']);
            $eastCity = Region::factory()->under($this->east)->create(['name' => 'East City']);
            $this->west = Region::factory()->create(['organization_id' => $this->organization->id, 'name' => 'West']);

            $this->b1 = Branch::factory()->in($eastCity)->create(['branch_code' => 'B1']);
            $this->b2 = Branch::factory()->in($this->west)->create(['branch_code' => 'B2']);
            $this->franchise = Franchise::factory()->in($this->east)->create();
            $this->franchiseBranch = Branch::factory()->ownedBy($this->franchise)->create(['branch_code' => 'FB']);
            $this->client = B2bClient::factory()->servicedBy($this->b1)->create();
        });

        $regionalHr = $this->asSystem(fn () => $this->customRole('Regional HR', ScopeLevel::Region, [Permission::ManageStaff, Permission::ViewBranches]));

        $this->staff = [
            'super_admin' => $this->staff(SystemRole::SuperAdmin),
            'regional_hr' => $this->staff($regionalHr, ['region_id' => $this->east->id]),
            'franchise_owner' => $this->staff(SystemRole::FranchiseOwner, ['franchise_id' => $this->franchise->id]),
            'b1_admin' => $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->b1->id]),
            'b1_front_desk' => $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->b1->id]),
            'b2_admin' => $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->b2->id]),
            'fb_front_desk' => $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->franchiseBranch->id]),
            'client_user' => $this->staff(SystemRole::B2bClientUser, ['b2b_client_id' => $this->client->id]),
        ];
    }

    public function test_branches_visible_at_each_scope_level(): void
    {
        $this->assertSame(['B1', 'B2', 'FB'], $this->branchCodesSeenBy('super_admin'));

        // East includes its child city and the franchise registered in East.
        $this->assertSame(['B1', 'FB'], $this->branchCodesSeenBy('regional_hr'));
    }

    public function test_staff_visible_at_each_scope_level(): void
    {
        $this->assertSame(array_keys($this->staff), $this->staffSeenBy('super_admin'));
        $this->assertSame(
            ['regional_hr', 'franchise_owner', 'b1_admin', 'b1_front_desk', 'fb_front_desk'],
            $this->staffSeenBy('regional_hr'),
        );
        $this->assertSame(['franchise_owner', 'fb_front_desk'], $this->staffSeenBy('franchise_owner'));
        $this->assertSame(['b1_admin', 'b1_front_desk'], $this->staffSeenBy('b1_admin'));
        $this->assertSame(['b2_admin'], $this->staffSeenBy('b2_admin'));
    }

    public function test_rows_outside_scope_are_404_not_403(): void
    {
        $this->actingAsStaff($this->staff['b1_admin'])
            ->getJson("/api/v1/users/{$this->staff['fb_front_desk']->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->actingAsStaff($this->staff['franchise_owner'])
            ->getJson("/api/v1/users/{$this->staff['b1_front_desk']->id}")
            ->assertNotFound();

        $this->actingAsStaff($this->staff['regional_hr'])
            ->getJson("/api/v1/branches/{$this->b2->id}")
            ->assertNotFound();

        $this->actingAsStaff($this->staff['b1_admin'])
            ->getJson("/api/v1/users/{$this->staff['b1_front_desk']->id}")
            ->assertOk();
    }

    public function test_missing_permission_is_403(): void
    {
        $this->actingAsStaff($this->staff['b1_front_desk'])
            ->getJson('/api/v1/users')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAsStaff($this->staff['b1_admin'])
            ->getJson('/api/v1/branches')
            ->assertForbidden();
    }

    public function test_scope_cannot_be_escaped_with_filters(): void
    {
        $this->actingAsStaff($this->staff['b1_admin'])
            ->getJson("/api/v1/users?filter[branch_id]={$this->franchiseBranch->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_me_reports_the_resolved_scope(): void
    {
        $this->actingAsStaff($this->staff['regional_hr'])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.scope.level', 'region')
            ->assertJsonPath('data.scope.franchise_ids', [$this->franchise->id])
            ->assertJsonCount(2, 'data.scope.region_ids')
            ->assertJsonCount(2, 'data.scope.branch_ids');

        $this->actingAsStaff($this->staff['franchise_owner'])
            ->getJson('/api/v1/me')
            ->assertJsonPath('data.scope.level', 'franchise')
            ->assertJsonPath('data.scope.branch_ids', [$this->franchiseBranch->id]);
    }

    /** @return list<string> */
    private function branchCodesSeenBy(string $staffKey): array
    {
        $codes = $this->actingAsStaff($this->staff[$staffKey])
            ->getJson('/api/v1/branches?sort=branch_code')
            ->assertOk()
            ->json('data.*.branch_code');

        return $codes;
    }

    /** @return list<string> staff keys, in the order of $this->staff */
    private function staffSeenBy(string $staffKey): array
    {
        $visibleIds = $this->actingAsStaff($this->staff[$staffKey])
            ->getJson('/api/v1/users?limit=200')
            ->assertOk()
            ->json('data.*.id');

        return array_keys(array_filter($this->staff, fn (User $user) => in_array($user->id, $visibleIds, true)));
    }

    /** @param  list<Permission>  $permissions */
    private function customRole(string $name, ScopeLevel $scopeLevel, array $permissions): Role
    {
        $role = new Role(['name' => $name, 'scope_level' => $scopeLevel]);
        $role->organization_id = $this->organization->id;
        $role->is_system = false;
        $role->save();
        $role->permissionEntries()->sync(
            PermissionEntry::query()->whereIn('name', array_map(fn ($p) => $p->value, $permissions))->pluck('id'),
        );

        return $role;
    }
}
