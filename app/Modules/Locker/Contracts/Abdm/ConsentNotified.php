<?php

namespace App\Modules\Locker\Contracts\Abdm;

/** A consent artefact about records we hold was granted, revoked, expired or denied. */
final class ConsentNotified implements HipCallback
{
    public const GRANTED = 'GRANTED';

    public const REVOKED = 'REVOKED';

    public const EXPIRED = 'EXPIRED';

    public const DENIED = 'DENIED';

    public function __construct(
        public readonly string $requestId,
        public readonly string $hipId,
        public readonly string $consentArtefactId,
        /** One of the constants above. */
        public readonly string $status,
        /** Present when granted. */
        public readonly ?ConsentArtefact $artefact,
    ) {}

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function apiName(): string
    {
        return 'consent_notify';
    }

    public function transactionId(): ?string
    {
        return null;
    }
}
