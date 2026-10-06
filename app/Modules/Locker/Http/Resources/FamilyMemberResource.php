<?php

namespace App\Modules\Locker\Http\Resources;

use App\Modules\Booking\Services\PatientProfile;
use App\Modules\Locker\Models\FamilyMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A family member. `can_switch` members have their own UHID: send their
 * patient ID in the X-Patient-Id header to open their records.
 *
 * @mixin FamilyMember
 */
final class FamilyMemberResource extends JsonResource
{
    public function __construct(FamilyMember $member, private readonly ?PatientProfile $patient)
    {
        parent::__construct($member);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->patient->name ?? $this->name,
            'relation' => $this->relation,
            'dob' => $this->patient?->dob?->toDateString() ?? $this->dob?->toDateString(),
            'patient' => $this->patient === null ? null : PatientProfileResource::make($this->patient)->toArray($request),
            'can_switch' => $this->patient !== null,
            'created_at' => $this->created_at,
        ];
    }
}
