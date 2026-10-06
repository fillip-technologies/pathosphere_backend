<?php

namespace App\Modules\Locker\Contracts\Abdm;

/** A verified call from the ABDM gateway to us as a Health Information Provider. */
interface HipCallback
{
    /** The gateway's ID for this call; a retry carries the same one. */
    public function requestId(): string;

    /** How abdm_requests names it. */
    public function apiName(): string;

    public function transactionId(): ?string;
}
