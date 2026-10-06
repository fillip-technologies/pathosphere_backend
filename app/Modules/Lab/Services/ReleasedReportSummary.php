<?php

namespace App\Modules\Lab\Services;

use Carbon\CarbonImmutable;

/** A released report a patient may link to their ABHA. */
final class ReleasedReportSummary
{
    public function __construct(
        public readonly string $reportId,
        public readonly string $organizationId,
        public readonly string $patientId,
        public readonly string $labBranchId,
        public readonly string $labName,
        public readonly int $version,
        public readonly CarbonImmutable $orderDate,
        public readonly CarbonImmutable $releasedAt,
    ) {}
}
