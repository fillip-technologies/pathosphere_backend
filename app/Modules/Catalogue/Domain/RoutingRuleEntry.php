<?php

namespace App\Modules\Catalogue\Domain;

/** An active routing rule for one source branch. A null test means "any test". */
final class RoutingRuleEntry
{
    public function __construct(
        public readonly ?string $testId,
        public readonly string $processingBranchId,
        public readonly int $priority,
    ) {}
}
