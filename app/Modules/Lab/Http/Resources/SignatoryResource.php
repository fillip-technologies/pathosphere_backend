<?php

namespace App\Modules\Lab\Http\Resources;

use App\Modules\Lab\Models\Signatory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A signatory row. The signature image never leaves private storage except
 * inside report PDFs.
 *
 * @mixin Signatory
 */
final class SignatoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'branch_id' => $this->branch_id,
            'department_id' => $this->department_id,
            'signing_discipline' => $this->signing_discipline,
            'qualification' => $this->qualification,
            'council_name' => $this->council_name,
            'registration_no' => $this->registration_no,
            'hpr_id' => $this->hpr_id,
            'valid_till' => $this->valid_till?->toDateString(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
