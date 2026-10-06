<?php

namespace App\Modules\Network\Contracts;

use App\Modules\Network\Enums\FranchiseDocumentType;

/** The identifiers on file that a KYC document should prove. */
final class KycCheckRequest
{
    public function __construct(
        public readonly FranchiseDocumentType $documentType,
        public readonly string $legalName,
        public readonly string $pan,
        public readonly ?string $gstin,
        public readonly ?string $bankAccountNo,
        public readonly ?string $bankIfsc,
    ) {}
}
