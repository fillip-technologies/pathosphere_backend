<?php

namespace App\Modules\Lab\Services;

use Carbon\CarbonImmutable;

/** One released report version as the health locker copies it. */
final class ReleasedReportCopy
{
    /**
     * @param  list<string>  $testNames
     * @param  list<ReleasedResult>  $results
     */
    public function __construct(
        public readonly string $reportId,
        public readonly string $organizationId,
        public readonly string $patientId,
        public readonly int $version,
        /** When the sample was taken: the order date. Trends are plotted on it. */
        public readonly CarbonImmutable $orderDate,
        public readonly CarbonImmutable $releasedAt,
        public readonly string $labName,
        public readonly array $testNames,
        /** The version this one corrected, if any. */
        public readonly ?string $previousVersionReportId,
        /** A released correction of this version, if any. */
        public readonly ?string $nextVersionReportId,
        public readonly bool $pdfReady,
        public readonly array $results,
    ) {}
}
