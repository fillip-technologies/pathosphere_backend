<?php

namespace App\Modules\Locker\Contracts\Abdm;

use Carbon\CarbonImmutable;

/** A health information user asks, under a consent artefact, for the records it covers. */
final class HealthInformationRequested implements HipCallback
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $transactionId,
        public readonly string $hipId,
        public readonly string $consentArtefactId,
        public readonly CarbonImmutable $dateFrom,
        public readonly CarbonImmutable $dateTo,
        public readonly string $dataPushUrl,
        /** The receiver's public key and nonce. */
        public readonly KeyMaterial $keyMaterial,
    ) {}

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function apiName(): string
    {
        return 'health_information_request';
    }

    public function transactionId(): string
    {
        return $this->transactionId;
    }
}
