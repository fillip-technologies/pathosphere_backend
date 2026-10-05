<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use App\Modules\Shared\Scoping\ScopeLevel;
use LogicException;

/**
 * Turns a user's role and scope column into the ScopeContext applied to all
 * their queries (spec §4.1). Region and franchise scopes are expanded to the
 * IDs they cover once, here, instead of in every query.
 */
final class ScopeResolver
{
    public function __construct(
        private readonly NetworkDirectory $network,
        private readonly CurrentScope $currentScope,
    ) {}

    public function forUser(User $user): ScopeContext
    {
        $organizationId = $user->organization_id;

        // Expanding the tree needs to see the whole organization.
        return $this->currentScope->runAs(ScopeContext::system($organizationId), fn (): ScopeContext => match ($user->role->scope_level) {
            ScopeLevel::Organization => ScopeContext::organization($organizationId),
            ScopeLevel::Region => $this->regionScope($organizationId, $this->required($user->region_id, 'region_id')),
            ScopeLevel::Franchise => $this->franchiseScope($organizationId, $this->required($user->franchise_id, 'franchise_id')),
            ScopeLevel::Branch => ScopeContext::branch($organizationId, $this->required($user->branch_id, 'branch_id')),
            ScopeLevel::B2bClient => ScopeContext::b2bClient($organizationId, $this->required($user->b2b_client_id, 'b2b_client_id')),
        });
    }

    private function regionScope(string $organizationId, string $regionId): ScopeContext
    {
        $regionIds = $this->network->regionTreeIds($regionId);

        return ScopeContext::region(
            $organizationId,
            $regionIds,
            $this->network->franchiseIdsInRegions($regionIds),
            $this->network->branchIdsInRegions($regionIds),
        );
    }

    private function franchiseScope(string $organizationId, string $franchiseId): ScopeContext
    {
        return ScopeContext::franchise($organizationId, $franchiseId, $this->network->branchIdsOfFranchise($franchiseId));
    }

    private function required(?string $value, string $column): string
    {
        // UserService guarantees this; reaching here means bad data, so fail closed.
        return $value ?? throw new LogicException("User is missing {$column} for their role's scope level.");
    }
}
