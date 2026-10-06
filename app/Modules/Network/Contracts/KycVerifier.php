<?php

namespace App\Modules\Network\Contracts;

/**
 * KYC vendor boundary (spec §3: PAN, GSTIN and bank account checks). Only the
 * outcome and the vendor's reference are stored, never the vendor payload.
 */
interface KycVerifier
{
    public function verify(KycCheckRequest $request): KycCheckResult;
}
