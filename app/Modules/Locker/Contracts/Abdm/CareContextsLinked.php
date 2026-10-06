<?php

namespace App\Modules\Locker\Contracts\Abdm;

/** ABDM's answer to our request to link care contexts to a patient's ABHA. */
final class CareContextsLinked implements HipCallback
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $respondingToRequestId,
        public readonly ?HipError $error,
    ) {}

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function apiName(): string
    {
        return 'on_link_care_contexts';
    }

    public function transactionId(): ?string
    {
        return null;
    }
}
