<?php

namespace App\Modules\Locker\Services;

use App\Modules\Locker\Contracts\DigiLocker\DigiLockerDocument;

/** A document in the patient's DigiLocker, and whether it is already in this locker. */
final class DigiLockerListing
{
    public function __construct(
        public readonly DigiLockerDocument $document,
        public readonly bool $importable,
        public readonly ?string $importedRecordId,
    ) {}
}
