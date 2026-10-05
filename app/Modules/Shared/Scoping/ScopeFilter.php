<?php

namespace App\Modules\Shared\Scoping;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Eloquent scope that limits every query on a scoped model to the
 * current ScopeContext (spec §4.2). This is the application's replacement
 * for row-level security, which MySQL does not have.
 */
final class ScopeFilter implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $scope = app(CurrentScope::class)->get() ?? throw MissingScope::forModel($model::class);

        if (! $model instanceof HasScopeColumns) {
            throw new \LogicException($model::class.' is filtered by scope but does not implement HasScopeColumns.');
        }

        $columns = $model::scopeColumns();
        $table = $model->getTable();

        if ($columns->organization !== null && $scope->organizationId !== null) {
            $builder->where("{$table}.{$columns->organization}", $scope->organizationId);
        }

        if ($scope->isSystem() || $scope->level === ScopeLevel::Organization || $columns->visibleToWholeOrganization) {
            return;
        }

        // Each entry: a column on this model and the IDs that make a row visible.
        $visibleBy = $this->visibilityRules($scope, $columns);

        if ($visibleBy === []) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(function (Builder $query) use ($visibleBy, $table): void {
            foreach ($visibleBy as [$column, $ids]) {
                $query->orWhereIn("{$table}.{$column}", $ids);
            }
        });
    }

    /**
     * @return list<array{string, list<string>}>
     */
    private function visibilityRules(ScopeContext $scope, ScopeColumns $columns): array
    {
        $byBranch = array_map(
            fn (?string $column): array => [$column, $scope->branchIds],
            [$columns->branch, $columns->processingBranch, ...$columns->otherBranches],
        );

        $candidates = match ($scope->level) {
            ScopeLevel::Region => [
                [$columns->region, $scope->regionIds],
                [$columns->franchise, $scope->franchiseIds],
                ...$byBranch,
            ],
            ScopeLevel::Franchise => [
                [$columns->franchise, $scope->franchiseIds],
                ...$byBranch,
            ],
            ScopeLevel::Branch => $byBranch,
            ScopeLevel::B2bClient => [
                [$columns->b2bClient, $scope->b2bClientId === null ? [] : [$scope->b2bClientId]],
            ],
            default => [],
        };

        // Drop rules this model has no column for, and rules with no IDs
        // (whereIn on an empty list would match nothing anyway).
        return array_values(array_filter(
            $candidates,
            fn (array $rule): bool => $rule[0] !== null && $rule[1] !== [],
        ));
    }
}
