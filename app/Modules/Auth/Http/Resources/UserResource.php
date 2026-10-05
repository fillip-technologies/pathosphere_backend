<?php

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Auth\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'employee_code' => $this->employee_code,
            'status' => $this->status,
            'role' => [
                'id' => $this->role->id,
                'name' => $this->role->name,
                'scope_level' => $this->role->scope_level,
            ],
            'region_id' => $this->region_id,
            'franchise_id' => $this->franchise_id,
            'branch_id' => $this->branch_id,
            'b2b_client_id' => $this->b2b_client_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
