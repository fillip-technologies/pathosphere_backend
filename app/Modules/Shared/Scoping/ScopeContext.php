<?php

namespace App\Modules\Shared\Scoping;

/**
 * The slice of the network the current request may see (spec §4). Resolved
 * once per request from the user's role and scope columns, then applied to
 * every scoped query by BelongsToScope.
 *
 * Region and franchise scopes carry the IDs they expand to (child regions,
 * the franchises and branches inside them), so queries never walk the tree.
 */
final class ScopeContext
{
    /**
     * @param  list<string>  $regionIds
     * @param  list<string>  $franchiseIds
     * @param  list<string>  $branchIds
     */
    private function __construct(
        public readonly ?ScopeLevel $level,
        public readonly ?string $organizationId,
        public readonly array $regionIds = [],
        public readonly array $franchiseIds = [],
        public readonly array $branchIds = [],
        public readonly ?string $b2bClientId = null,
    ) {}

    public static function organization(string $organizationId): self
    {
        return new self(ScopeLevel::Organization, $organizationId);
    }

    /**
     * @param  list<string>  $regionIds  the user's region and all its descendants
     * @param  list<string>  $franchiseIds  franchises inside those regions
     * @param  list<string>  $branchIds  branches inside those regions
     */
    public static function region(string $organizationId, array $regionIds, array $franchiseIds, array $branchIds): self
    {
        return new self(ScopeLevel::Region, $organizationId, $regionIds, $franchiseIds, $branchIds);
    }

    /** @param  list<string>  $branchIds  the franchise's branches */
    public static function franchise(string $organizationId, string $franchiseId, array $branchIds): self
    {
        return new self(ScopeLevel::Franchise, $organizationId, franchiseIds: [$franchiseId], branchIds: $branchIds);
    }

    public static function branch(string $organizationId, string $branchId): self
    {
        return new self(ScopeLevel::Branch, $organizationId, branchIds: [$branchId]);
    }

    public static function b2bClient(string $organizationId, string $b2bClientId): self
    {
        return new self(ScopeLevel::B2bClient, $organizationId, b2bClientId: $b2bClientId);
    }

    /**
     * Background jobs, seeders and pre-login lookups (spec §4.3). Sees every
     * row, limited to one organization when one is given. Use it explicitly;
     * a missing scope is an error, never "see everything".
     */
    public static function system(?string $organizationId = null): self
    {
        return new self(null, $organizationId);
    }

    public function isSystem(): bool
    {
        return $this->level === null;
    }

    /**
     * Whether the caller acts for this branch: the whole organization, or a
     * region, franchise or branch scope that contains it. B2B client users
     * never act for a branch.
     */
    public function coversBranch(string $branchId): bool
    {
        return match ($this->level) {
            null, ScopeLevel::Organization => true,
            ScopeLevel::B2bClient => false,
            default => in_array($branchId, $this->branchIds, true),
        };
    }

    public function franchiseId(): ?string
    {
        return $this->level === ScopeLevel::Franchise ? $this->franchiseIds[0] : null;
    }
}
