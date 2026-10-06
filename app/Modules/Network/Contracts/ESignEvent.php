<?php

namespace App\Modules\Network\Contracts;

use Carbon\CarbonImmutable;

/** An e-sign webhook, translated out of the vendor's format. */
final class ESignEvent
{
    public const SIGNED = 'signed';

    /** Declined by the signer or expired unsigned: the agreement goes back to draft. */
    public const DECLINED = 'declined';

    public function __construct(
        public readonly string $reference,
        public readonly string $outcome,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
