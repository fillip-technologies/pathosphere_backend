<?php

namespace App\Modules\Locker\Contracts\Abdm;

/** ABDM's answer to our request for a link token for one patient at one of our facilities. */
final class LinkTokenIssued implements HipCallback
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $respondingToRequestId,
        public readonly ?string $linkToken,
        public readonly ?HipError $error,
    ) {}

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function apiName(): string
    {
        return 'on_generate_link_token';
    }

    public function transactionId(): ?string
    {
        return null;
    }
}
