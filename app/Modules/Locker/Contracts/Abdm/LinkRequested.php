<?php

namespace App\Modules\Locker\Contracts\Abdm;

/** The patient chose which of the discovered reports to link; we must prove it is them. */
final class LinkRequested implements HipCallback
{
    /** @param  list<string>  $careContextReferences */
    public function __construct(
        public readonly string $requestId,
        public readonly string $transactionId,
        public readonly string $hipId,
        public readonly ?string $abhaAddress,
        public readonly string $patientReference,
        public readonly array $careContextReferences,
    ) {}

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function apiName(): string
    {
        return 'care_context_link_init';
    }

    public function transactionId(): string
    {
        return $this->transactionId;
    }
}
