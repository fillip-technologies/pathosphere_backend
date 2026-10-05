<?php

namespace App\Modules\Booking\Http\Resources;

use App\Modules\Booking\Models\AbdmRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A queued Scan and Share profile, without the encrypted ABHA number.
 *
 * @mixin AbdmRequest
 */
final class ProfileShareResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->payload_masked ?? [];

        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'name' => $profile['name'] ?? null,
            'gender' => $profile['gender'] ?? null,
            'year_of_birth' => $profile['year_of_birth'] ?? null,
            'mobile' => $profile['mobile'] ?? null,
            'abha_address' => $profile['abha_address'] ?? null,
            'received_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
