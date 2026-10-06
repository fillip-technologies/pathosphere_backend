<?php

namespace App\Modules\Network\Contracts;

final class ESignRequest
{
    public function __construct(
        public readonly string $agreementId,
        public readonly string $agreementNo,
        public readonly string $signerName,
        public readonly string $signerEmail,
        public readonly string $signerPhone,
        public readonly string $documentPdf,
    ) {}
}
