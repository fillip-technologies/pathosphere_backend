<?php

namespace App\Modules\Locker\Http\Resources;

use App\Modules\Locker\Enums\ConsentStatus;
use App\Modules\Locker\Models\Consent;
use App\Modules\Locker\Models\RecordShare;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A share the patient made: with whom, why, which records and until when.
 * A granted consent past its end reads as `expired` even before the nightly
 * job records it.
 *
 * @mixin Consent
 */
final class ShareResource extends JsonResource
{
    /** @param  list<array{medical_record_id: string, title: string, token: string, url: string}>  $links */
    public function __construct(Consent $consent, private readonly array $links = [])
    {
        parent::__construct($consent);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RecordShare|null $first */
        $first = $this->shares->first();
        $status = $this->status === ConsentStatus::Granted && ! $this->isInForce() ? ConsentStatus::Expired : $this->status;

        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'shared_with' => [
                'type' => $first?->shared_with_type,
                'name' => $this->requester,
            ],
            'purpose' => $this->purpose,
            'status' => $status,
            'medical_record_ids' => $this->shares->pluck('medical_record_id')->values()->all(),
            'granted_at' => $this->granted_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'revoked_at' => $this->revoked_at?->toIso8601ZuluString(),
            // Shown once, when a link share is made: only hashes are stored.
            'links' => $this->when($this->links !== [], fn () => array_map(fn (array $link) => [
                'medical_record_id' => $link['medical_record_id'],
                'url' => $link['url'],
            ], $this->links)),
            'created_at' => $this->created_at,
        ];
    }
}
