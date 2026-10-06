<?php

namespace App\Modules\Network\Enums;

enum FranchiseDocumentStatus: string
{
    case Uploaded = 'uploaded';
    /** The KYC vendor's check passed; a person still verifies it. */
    case AutoVerified = 'auto_verified';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function awaitsReview(): bool
    {
        return $this === self::Uploaded || $this === self::AutoVerified;
    }
}
