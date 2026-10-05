<?php

namespace Tests\Unit\Auth;

use App\Modules\Auth\Domain\RoleAssignmentPolicy;
use App\Modules\Auth\Domain\StaffPlacement;
use App\Modules\Auth\Permissions\Permission;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Shared\Scoping\ScopeLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoleRulesTest extends TestCase
{
    /** @return iterable<string, array{ScopeLevel, ScopeLevel, bool}> */
    public static function assignments(): iterable
    {
        yield 'HQ assigns anything' => [ScopeLevel::Organization, ScopeLevel::Region, true];
        yield 'region assigns branch' => [ScopeLevel::Region, ScopeLevel::Branch, true];
        yield 'region cannot assign HQ' => [ScopeLevel::Region, ScopeLevel::Organization, false];
        yield 'franchise assigns branch' => [ScopeLevel::Franchise, ScopeLevel::Branch, true];
        yield 'franchise cannot assign region' => [ScopeLevel::Franchise, ScopeLevel::Region, false];
        yield 'franchise cannot assign B2B' => [ScopeLevel::Franchise, ScopeLevel::B2bClient, false];
        yield 'branch assigns branch' => [ScopeLevel::Branch, ScopeLevel::Branch, true];
        yield 'branch cannot assign franchise' => [ScopeLevel::Branch, ScopeLevel::Franchise, false];
        yield 'B2B user assigns nothing' => [ScopeLevel::B2bClient, ScopeLevel::B2bClient, false];
    }

    #[DataProvider('assignments')]
    public function test_roles_can_only_be_assigned_inside_the_granter_scope(ScopeLevel $granter, ScopeLevel $role, bool $allowed): void
    {
        $this->assertSame($allowed, RoleAssignmentPolicy::assignmentRefusal($granter, $role) === null);
    }

    public function test_role_editors_cannot_grant_permissions_they_lack(): void
    {
        $notHeld = RoleAssignmentPolicy::permissionsNotHeld(
            [Permission::CreateOrder],
            [Permission::CreateOrder, Permission::ApproveRefund],
        );

        $this->assertSame([Permission::ApproveRefund], $notHeld);
    }

    public function test_a_placement_sets_exactly_the_column_for_the_role_scope(): void
    {
        $placement = StaffPlacement::forRole(ScopeLevel::Branch, ['branch_id' => 'b-1']);

        $this->assertInstanceOf(StaffPlacement::class, $placement);
        $this->assertSame(['region_id' => null, 'franchise_id' => null, 'branch_id' => 'b-1', 'b2b_client_id' => null], $placement->toColumns());

        $this->assertSame(['field' => 'branch_id', 'issue' => 'Required for a branch-level role.'], StaffPlacement::forRole(ScopeLevel::Branch, []));
        $this->assertSame('region_id', StaffPlacement::forRole(ScopeLevel::Organization, ['region_id' => 'r-1'])['field'] ?? null);
    }

    public function test_every_system_role_only_holds_permissions_its_scope_allows(): void
    {
        foreach (SystemRole::cases() as $role) {
            foreach ($role->permissions() as $permission) {
                $this->assertTrue(
                    $permission->isAllowedFor($role->scopeLevel()),
                    "{$role->value} ({$role->scopeLevel()->value}) holds {$permission->value}, which that scope may not hold.",
                );
            }
        }
    }

    public function test_mfa_is_required_for_the_roles_named_in_the_spec(): void
    {
        $needsMfa = fn (SystemRole $role): bool => array_filter($role->permissions(), fn (Permission $p) => $p->requiresMfa()) !== [];

        foreach ([SystemRole::SuperAdmin, SystemRole::HqFinance, SystemRole::FranchiseManager, SystemRole::Signatory] as $role) {
            $this->assertTrue($needsMfa($role), "{$role->value} must require MFA (spec §10.4).");
        }

        $this->assertFalse($needsMfa(SystemRole::FrontDesk));
    }

    public function test_no_branch_role_can_approve_settlements(): void
    {
        $this->assertFalse(Permission::ApproveSettlement->isAllowedFor(ScopeLevel::Branch));
    }
}
