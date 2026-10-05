<?php

namespace App\Modules\Booking\Contracts;

/**
 * A verified or newly created ABHA. The X-Token is the patient's ABDM user
 * token: kept only for the desk session (spec §5.7 rule 5).
 */
final class AbhaSession
{
    /** @param  list<string>  $addressSuggestions */
    public function __construct(
        public readonly AbhaProfile $profile,
        public readonly string $xToken,
        public readonly string $requestId,
        public readonly array $addressSuggestions = [],
    ) {}
}
