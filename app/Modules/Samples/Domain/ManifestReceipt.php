<?php

namespace App\Modules\Samples\Domain;

use App\Modules\Samples\Enums\ManifestStatus;

/**
 * Status of a manifest while its samples are scanned in (spec §5.4):
 * received once every sample is scanned (accepted or rejected), partially
 * received while some are still unaccounted for.
 */
final class ManifestReceipt
{
    public static function statusAfterScans(int $scannedSamples, int $totalSamples): ManifestStatus
    {
        return $scannedSamples >= $totalSamples ? ManifestStatus::Received : ManifestStatus::PartiallyReceived;
    }
}
