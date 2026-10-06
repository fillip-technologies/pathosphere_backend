<?php

namespace App\Modules\Locker\Services;

use App\Modules\Locker\Models\Consent;

/**
 * A new share, with its links when shared by link. Links carry the only
 * copy of their tokens (only hashes are stored), so they are shown once.
 */
final class CreatedShare
{
    /** @param  list<array{medical_record_id: string, title: string, token: string, url: string}>  $links */
    public function __construct(
        public readonly Consent $consent,
        public readonly array $links,
    ) {}
}
