<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RolePermissionsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct'],
        ];
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return array_values($this->validated('permissions'));
    }
}
