<?php

namespace App\Modules\Samples\Services;

use App\Modules\Samples\Errors\SampleError;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\MissingScope;

/**
 * Seeing a sample or manifest is not the same as handling it: both ends of a
 * route see a manifest, but only the sender dispatches it and only the
 * receiver scans it in. Actions check the caller acts for the right branch.
 */
final class BranchGuard
{
    public function __construct(private readonly CurrentScope $currentScope) {}

    public function assertActsFor(string $branchId, string $action): void
    {
        $scope = $this->currentScope->get() ?? throw new MissingScope('No scope is set for a sample action.');

        if (! $scope->coversBranch($branchId)) {
            throw SampleError::notForThisBranch($action);
        }
    }
}
