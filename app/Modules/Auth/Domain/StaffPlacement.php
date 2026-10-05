<?php

namespace App\Modules\Auth\Domain;

use App\Modules\Shared\Scoping\ScopeLevel;

/**
 * Where a staff member sits in the network, as stored on `users`. Exactly the
 * column matching the role's scope level is set (spec §7.2), so a user can
 * never be "a branch user without a branch" or carry stray scope columns.
 */
final class StaffPlacement
{
    private const COLUMNS = ['region_id', 'franchise_id', 'branch_id', 'b2b_client_id'];

    private function __construct(
        public readonly ?string $regionId,
        public readonly ?string $franchiseId,
        public readonly ?string $branchId,
        public readonly ?string $b2bClientId,
    ) {}

    /**
     * @param  array<string, string|null>  $columns  region_id / franchise_id / branch_id / b2b_client_id as sent
     * @return self|array{field: string, issue: string} the placement, or which field is wrong and why
     */
    public static function forRole(ScopeLevel $roleScope, array $columns): self|array
    {
        $required = self::requiredColumn($roleScope);

        foreach (self::COLUMNS as $column) {
            $value = $columns[$column] ?? null;

            if ($column === $required && $value === null) {
                return ['field' => $column, 'issue' => "Required for a {$roleScope->value}-level role."];
            }

            if ($column !== $required && $value !== null) {
                return ['field' => $column, 'issue' => "Must be empty for a {$roleScope->value}-level role."];
            }
        }

        return new self(
            $columns['region_id'] ?? null,
            $columns['franchise_id'] ?? null,
            $columns['branch_id'] ?? null,
            $columns['b2b_client_id'] ?? null,
        );
    }

    public static function requiredColumn(ScopeLevel $scope): ?string
    {
        return match ($scope) {
            ScopeLevel::Organization => null,
            ScopeLevel::Region => 'region_id',
            ScopeLevel::Franchise => 'franchise_id',
            ScopeLevel::Branch => 'branch_id',
            ScopeLevel::B2bClient => 'b2b_client_id',
        };
    }

    /** @return array<string, string|null> */
    public function toColumns(): array
    {
        return [
            'region_id' => $this->regionId,
            'franchise_id' => $this->franchiseId,
            'branch_id' => $this->branchId,
            'b2b_client_id' => $this->b2bClientId,
        ];
    }
}
