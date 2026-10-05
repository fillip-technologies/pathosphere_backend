<?php

namespace App\Modules\Booking\Contracts;

/** An ABDM OTP flow in progress. */
final class AbdmTransaction
{
    public function __construct(
        public readonly string $txnId,
        public readonly string $requestId,
    ) {}
}
