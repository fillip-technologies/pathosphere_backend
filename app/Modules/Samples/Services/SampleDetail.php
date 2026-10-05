<?php

namespace App\Modules\Samples\Services;

use App\Modules\Booking\Services\SampleOrderFacts;
use App\Modules\Catalogue\Services\SampleRequirement;
use App\Modules\Samples\Models\ManifestItem;
use App\Modules\Samples\Models\Sample;

/** A sample with everything a desk or lab needs to handle it, and where it has been. */
final class SampleDetail
{
    /**
     * @param  array<string, SampleRequirement>  $testsByOrderItem
     * @param  list<ManifestItem>  $journey  manifest legs, oldest first, with their manifest loaded
     */
    public function __construct(
        public readonly Sample $sample,
        public readonly SampleOrderFacts $order,
        public readonly array $testsByOrderItem,
        public readonly array $journey,
        public readonly ?string $redrawSampleId,
        public readonly string $labelZpl,
    ) {}
}
