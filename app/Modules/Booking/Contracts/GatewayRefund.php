<?php

namespace App\Modules\Booking\Contracts;

final class GatewayRefund
{
    public function __construct(
        public readonly string $refundId,
        /** False while the gateway is still processing; a webhook confirms later. */
        public readonly bool $isProcessed,
    ) {}
}
