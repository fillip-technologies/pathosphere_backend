<?php

namespace App\Modules\Locker\Contracts\Abdm;

/**
 * A patient, in their ABHA app, looking for their records at one of our
 * facilities. Only the identifiers ABDM marks as verified are trusted.
 */
final class DiscoveryRequested implements HipCallback
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $transactionId,
        public readonly string $hipId,
        public readonly ?string $abhaAddress,
        public readonly string $name,
        /** M, F or O. */
        public readonly string $gender,
        public readonly ?int $yearOfBirth,
        public readonly ?string $verifiedMobile,
        public readonly ?string $verifiedAbhaNumber,
        /** A UHID the patient typed: a hint among matches, never proof. */
        public readonly ?string $unverifiedUhid,
    ) {}

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function apiName(): string
    {
        return 'care_context_discover';
    }

    public function transactionId(): string
    {
        return $this->transactionId;
    }
}
