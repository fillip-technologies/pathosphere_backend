<?php

namespace App\Modules\Lab\Services;

use App\Modules\Booking\Services\SampleOrderFacts;
use App\Modules\Catalogue\Services\TestResultDefinition;
use App\Modules\Lab\Models\LabResult;
use App\Modules\Lab\Models\WorklistEntry;

/** A test at the lab with its patient, sample, parameters and current results. */
final class WorklistDetail
{
    /** @param  array<string, LabResult>  $resultsByParameter  the current run, by parameter ID */
    public function __construct(
        public readonly WorklistEntry $entry,
        public readonly SampleOrderFacts $order,
        public readonly string $barcode,
        public readonly string $sampleType,
        public readonly TestResultDefinition $test,
        public readonly array $resultsByParameter,
        public readonly string $etag,
    ) {}
}
