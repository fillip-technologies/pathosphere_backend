<?php

namespace App\Modules\Samples\Listeners;

use App\Modules\Samples\Events\SampleCollected;
use App\Modules\Samples\Events\SampleRerouted;
use App\Modules\Samples\Services\ManifestService;

/**
 * A drawn or re-routed sample joins the open manifest to its lab (spec §9
 * SampleCollected). Runs right after the collection commits, in the same
 * request, so the runner sees the bag at once.
 */
final class BagSampleForItsLab
{
    public function __construct(private readonly ManifestService $manifests) {}

    public function handle(SampleCollected|SampleRerouted $event): void
    {
        $this->manifests->bagForItsLab($event->sampleId, $event->organizationId);
    }
}
