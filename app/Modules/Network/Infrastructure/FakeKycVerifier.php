<?php

namespace App\Modules\Network\Infrastructure;

use App\Modules\Network\Contracts\KycCheckRequest;
use App\Modules\Network\Contracts\KycCheckResult;
use App\Modules\Network\Contracts\KycVerifier;
use App\Modules\Network\Enums\FranchiseDocumentType;
use Illuminate\Support\Str;

/**
 * Local and test stand-in for the KYC vendor: checks the format of the
 * identifier the paper proves, with no network call. PAN, GST and bank
 * papers are checked; everything else is left to a person.
 */
final class FakeKycVerifier implements KycVerifier
{
    public function verify(KycCheckRequest $request): KycCheckResult
    {
        $valid = match ($request->documentType) {
            FranchiseDocumentType::Pan => preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $request->pan) === 1,
            FranchiseDocumentType::GstCertificate => $request->gstin !== null && str_contains($request->gstin, $request->pan),
            FranchiseDocumentType::BankProof => $request->bankAccountNo !== null && preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', (string) $request->bankIfsc) === 1,
            default => null,
        };

        if ($valid === null) {
            return new KycCheckResult(KycCheckResult::NOT_CHECKED);
        }

        return new KycCheckResult($valid ? KycCheckResult::PASSED : KycCheckResult::FAILED, 'kyc_fake_'.Str::lower(Str::random(12)));
    }
}
