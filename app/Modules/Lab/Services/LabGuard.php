<?php

namespace App\Modules\Lab\Services;

use App\Modules\Lab\Errors\LabError;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\MissingScope;

/**
 * Seeing lab work is not the same as doing it: regional and franchise staff
 * may see a lab's tests and reports, but only that lab's own staff enter,
 * verify, rerun, sign and release them (spec §10: collection centres never
 * release reports).
 */
final class LabGuard
{
    public function __construct(private readonly CurrentScope $currentScope) {}

    public function assertActsFor(string $labId, string $action): void
    {
        $scope = $this->currentScope->get() ?? throw new MissingScope('No scope is set for a lab action.');

        if (! $scope->coversBranch($labId)) {
            throw LabError::notForThisLab($action);
        }
    }
}
