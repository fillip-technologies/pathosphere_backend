<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\Region;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\TestCase;

/** Creating and changing staff without privilege escalation (spec §4, §8). */
final class StaffManagementTest extends TestCase
{
    use BuildsStaff;
    use RefreshDatabase;

    private Branch $ownBranch;

    private Branch $otherBranch;

    private Region $region;

    private User $branchAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->asSystem(function (): void {
            $this->region = Region::factory()->create(['organization_id' => $this->organization->id]);
            $this->ownBranch = Branch::factory()->in($this->region)->create();
            $this->otherBranch = Branch::factory()->in($this->region)->create();
        });

        $this->branchAdmin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->ownBranch->id]);
    }

    public function test_a_branch_admin_adds_front_desk_staff_to_their_branch(): void
    {
        $response = $this->actingAsStaff($this->branchAdmin)
            ->postJson('/api/v1/users', $this->newStaff(SystemRole::FrontDesk, ['branch_id' => $this->ownBranch->id]))
            ->assertCreated()
            ->assertJsonPath('data.role.name', 'Front Desk')
            ->assertJsonPath('data.branch_id', $this->ownBranch->id)
            ->assertJsonPath('data.status', 'active');

        $this->assertSame('/api/v1/users/'.$response->json('data.id'), $response->headers->get('Location'));

        $this->postJson('/api/v1/auth/login', ['login_identifier' => 'new.staff@example.com', 'password' => 'Brand-new-pass-99'])
            ->assertJsonPath('data.status', 'authenticated');

        $this->assertSame(1, $this->asSystem(fn () => AuditLog::query()->where('action', 'user.create')->count()));
    }

    public function test_a_branch_admin_cannot_create_a_more_powerful_role(): void
    {
        $this->actingAsStaff($this->branchAdmin)
            ->postJson('/api/v1/users', $this->newStaff(SystemRole::RegionalManager, ['region_id' => $this->region->id]))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ROLE_NOT_ASSIGNABLE');

        $franchiseOwner = $this->staff(SystemRole::FranchiseOwner, ['franchise_id' => $this->asSystem(fn () => Franchise::factory()->in($this->region)->create()->id)]);
        $this->actingAsStaff($franchiseOwner)
            ->postJson('/api/v1/users', $this->newStaff(SystemRole::HqFinance, []))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ROLE_NOT_ASSIGNABLE');
    }

    public function test_staff_can_only_be_placed_where_the_granter_can_see(): void
    {
        $this->actingAsStaff($this->branchAdmin)
            ->postJson('/api/v1/users', $this->newStaff(SystemRole::FrontDesk, ['branch_id' => $this->otherBranch->id]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'branch_id');
    }

    public function test_the_placement_must_match_the_role_scope(): void
    {
        $superAdmin = $this->staff(SystemRole::SuperAdmin);

        $this->actingAsStaff($superAdmin)
            ->postJson('/api/v1/users', $this->newStaff(SystemRole::FrontDesk, []))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'branch_id');

        $this->actingAsStaff($superAdmin)
            ->postJson('/api/v1/users', $this->newStaff(SystemRole::FrontDesk, ['branch_id' => $this->ownBranch->id, 'region_id' => $this->region->id]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'region_id');
    }

    public function test_weak_passwords_and_duplicate_phones_are_rejected(): void
    {
        $this->actingAsStaff($this->branchAdmin)
            ->postJson('/api/v1/users', [
                ...$this->newStaff(SystemRole::FrontDesk, ['branch_id' => $this->ownBranch->id]),
                'password' => 'short',
                'phone' => $this->branchAdmin->phone,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'password'])
            ->assertJsonFragment(['field' => 'phone']);
    }

    public function test_disabling_staff_signs_them_out_everywhere(): void
    {
        $frontDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->ownBranch->id]);
        $theirToken = $this->tokenFor($frontDesk);

        $this->actingAsStaff($this->branchAdmin)
            ->postJson("/api/v1/users/{$frontDesk->id}/disable")
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        app('auth')->forgetGuards();
        $this->withToken($theirToken)->getJson('/api/v1/me')->assertUnauthorized();

        $this->actingAsStaff($this->branchAdmin)
            ->postJson("/api/v1/users/{$frontDesk->id}/disable")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
    }

    public function test_nobody_can_disable_delete_or_re_role_themselves(): void
    {
        $this->actingAsStaff($this->branchAdmin)
            ->postJson("/api/v1/users/{$this->branchAdmin->id}/disable")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CANNOT_CHANGE_OWN_ACCESS');

        $this->actingAsStaff($this->branchAdmin)
            ->deleteJson("/api/v1/users/{$this->branchAdmin->id}")
            ->assertStatus(422);
    }

    public function test_updates_need_the_current_etag(): void
    {
        $frontDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->ownBranch->id]);
        $this->actingAsStaff($this->branchAdmin);

        $this->patchJson("/api/v1/users/{$frontDesk->id}", ['name' => 'Renamed'])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        $etag = $this->getJson("/api/v1/users/{$frontDesk->id}")->assertOk()->headers->get('ETag');

        $this->patchJson("/api/v1/users/{$frontDesk->id}", ['name' => 'Renamed'], ['If-Match' => $etag])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->patchJson("/api/v1/users/{$frontDesk->id}", ['name' => 'Again'], ['If-Match' => $etag])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'PRECONDITION_FAILED');
    }

    /**
     * @param  array<string, string>  $placement
     * @return array<string, mixed>
     */
    private function newStaff(SystemRole $role, array $placement): array
    {
        return [
            'role_id' => $this->asSystem(fn () => Role::query()->whereNull('organization_id')->where('name', $role->value)->value('id')),
            'name' => 'New Staff',
            'email' => 'new.staff@example.com',
            'phone' => '9811122233',
            'password' => 'Brand-new-pass-99',
            ...$placement,
        ];
    }
}
