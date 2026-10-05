<?php

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Shared\Http\Validation\Formats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Create (POST) and partial update (PATCH) of a staff member. */
final class UserRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $userId = $this->route('user') instanceof User ? $this->route('user')->id : null;

        $rules = [
            'role_id' => [$required, 'uuid'],
            'name' => [$required, 'string', 'max:150'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => [$required, Formats::PHONE, Rule::unique('users', 'phone')->ignore($userId)],
            'employee_code' => [
                'sometimes', 'nullable', 'string', 'max:20',
                Rule::unique('users', 'employee_code')
                    ->where(fn ($query) => $query->where('organization_id', app(StaffContext::class)->user()->organization_id))
                    ->ignore($userId),
            ],
            'region_id' => ['sometimes', 'nullable', 'uuid'],
            'franchise_id' => ['sometimes', 'nullable', 'uuid'],
            'branch_id' => ['sometimes', 'nullable', 'uuid'],
            'b2b_client_id' => ['sometimes', 'nullable', 'uuid'],
        ];

        if ($creating) {
            $rules['password'] = ['required', 'string', 'max:200', Password::min(12)->letters()->numbers()];
        }

        return $rules;
    }
}
