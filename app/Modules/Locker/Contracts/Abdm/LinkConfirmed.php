<?php

namespace App\Modules\Locker\Contracts\Abdm;

/** The code the patient received, entered in their ABHA app. Holds the code: never log it. */
final class LinkConfirmed implements HipCallback
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $hipId,
        public readonly string $linkReference,
        public readonly string $code,
    ) {}

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function apiName(): string
    {
        return 'care_context_link_confirm';
    }

    public function transactionId(): ?string
    {
        return null;
    }
}
