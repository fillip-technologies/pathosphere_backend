<?php

namespace App\Modules\Booking\Contracts;

use Carbon\CarbonImmutable;

final class PaymentLink
{
    public function __construct(
        public readonly string $linkId,
        public readonly string $url,
        public readonly CarbonImmutable $expiresAt,
    ) {}
}
