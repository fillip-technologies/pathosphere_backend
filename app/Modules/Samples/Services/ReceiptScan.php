<?php

namespace App\Modules\Samples\Services;

use App\Modules\Samples\Enums\ManifestItemCondition;

/** One barcode scanned at the receiving lab and what the lab found. */
final class ReceiptScan
{
    public function __construct(
        public readonly string $barcode,
        /** Accepted or rejected; never pending. */
        public readonly ManifestItemCondition $condition,
        public readonly ?string $rejectionReason = null,
        public readonly ?string $rejectionNote = null,
    ) {}
}
