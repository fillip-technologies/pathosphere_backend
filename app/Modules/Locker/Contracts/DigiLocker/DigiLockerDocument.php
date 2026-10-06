<?php

namespace App\Modules\Locker\Contracts\DigiLocker;

use Carbon\CarbonImmutable;

/** A document in the patient's DigiLocker, as listed (the file is fetched separately). */
final class DigiLockerDocument
{
    public function __construct(
        /** DigiLocker's stable reference to the document, unique across DigiLocker. */
        public readonly string $uri,
        public readonly string $name,
        /** The issuer's document type code, e.g. VACER for a vaccination certificate. */
        public readonly ?string $docType,
        public readonly ?string $issuer,
        public readonly ?CarbonImmutable $issuedOn,
        /** @var list<string> */
        public readonly array $mimeTypes,
    ) {}
}
