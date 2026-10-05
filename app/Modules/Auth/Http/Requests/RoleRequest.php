<?php

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Shared\Scoping\ScopeLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) and partial update (PATCH) of a custom role. Scope level is fixed at creation. */
final class RoleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $roleId = $this->route('role') instanceof Role ? $this->route('role')->id : null;

        $rules = [
            'name' => [
                $creating ? 'required' : 'sometimes', 'string', 'max:60',
                Rule::unique('roles', 'name')
                    ->where(fn ($query) => $query->where('organization_id', app(StaffContext::class)->user()->organization_id))
                    ->ignore($roleId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        if ($creating) {
            $rules['scope_level'] = ['required', Rule::enum(ScopeLevel::class)];
        }

        return $rules;
    }
}
