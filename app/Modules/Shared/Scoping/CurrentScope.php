<?php

namespace App\Modules\Shared\Scoping;

/**
 * Holds the scope for the current request or job. Bound as a scoped
 * singleton, so it never leaks between requests or queue jobs.
 */
final class CurrentScope
{
    private ?ScopeContext $scope = null;

    public function set(ScopeContext $scope): void
    {
        $this->scope = $scope;
    }

    public function get(): ?ScopeContext
    {
        return $this->scope;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(ScopeContext $scope, callable $callback): mixed
    {
        $previous = $this->scope;
        $this->scope = $scope;

        try {
            return $callback();
        } finally {
            $this->scope = $previous;
        }
    }
}
