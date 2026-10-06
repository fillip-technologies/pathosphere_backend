<?php

namespace App\Modules\Ledger\Listeners;

use App\Modules\Ledger\Services\LedgerPartnerCharges;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Samples\Events\SampleRejected;
use App\Modules\Samples\Services\LabSamples;

/**
 * SampleRejected (spec §9): the redraw is free, so by default the original
 * charge stands and the partner pays once. When the organization's policy
 * says so (`reverse_partner_charge_on_rejection`), the charge for the
 * rejected tests is credited back instead.
 */
final class ReverseChargesForRejectedSample
{
    public function __construct(
        private readonly NetworkDirectory $network,
        private readonly LabSamples $samples,
        private readonly LedgerPartnerCharges $charges,
    ) {}

    public function handle(SampleRejected $event): void
    {
        $settings = $this->network->organizationSettings($event->organizationId);

        if (! (bool) ($settings['reverse_partner_charge_on_rejection'] ?? config('pathology.ledger.reverse_partner_charge_on_rejection'))) {
            return;
        }

        $sample = $this->samples->facts($event->organizationId, $event->sampleId);

        if ($sample !== null) {
            $this->charges->reverse($event->organizationId, $sample->orderItemIds, "Sample {$sample->barcode} rejected");
        }
    }
}
