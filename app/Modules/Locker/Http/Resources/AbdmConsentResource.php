<?php

namespace App\Modules\Locker\Http\Resources;

use App\Modules\Locker\Enums\ConsentStatus;
use App\Modules\Locker\Models\Consent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An ABDM consent artefact about the patient's records. A granted consent
 * past its end reads as `expired` even before the nightly job records it.
 *
 * @mixin Consent
 */
final class AbdmConsentResource extends JsonResource
{
    /** @param  array<string, string>  $recordIdsByReference  medical record ID by care context reference */
    public function __construct(Consent $consent, private readonly array $recordIdsByReference)
    {
        parent::__construct($consent);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $scope = $this->scope;
        $status = $this->status === ConsentStatus::Granted && ! $this->isInForce() ? ConsentStatus::Expired : $this->status;
        $references = (array) ($scope['care_context_references'] ?? []);

        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'consent_artefact_id' => $this->consent_artefact_id,
            'requester' => $this->requester,
            'purpose' => $this->purpose,
            'purpose_code' => $scope['purpose_code'] ?? null,
            'status' => $status,
            'hi_types' => $scope['hi_types'] ?? [],
            'date_from' => $scope['date_from'] ?? null,
            'date_to' => $scope['date_to'] ?? null,
            'medical_record_ids' => array_values(array_filter(array_map(fn ($reference) => $this->recordIdsByReference[$reference] ?? null, $references))),
            'granted_at' => $this->granted_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'revoked_at' => $this->revoked_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at,
        ];
    }
}
