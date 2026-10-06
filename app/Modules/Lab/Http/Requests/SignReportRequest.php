<?php

namespace App\Modules\Lab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The authenticator code (spec §10.4: signing re-asks it) and, optionally,
 * the departments to sign; without them every department the signer may
 * sign now is signed.
 */
final class SignReportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
            'department_ids' => ['nullable', 'array', 'min:1', 'max:20'],
            'department_ids.*' => ['uuid', 'distinct'],
        ];
    }

    /** @return list<string>|null */
    public function departmentIds(): ?array
    {
        $ids = $this->validated('department_ids');

        return $ids === null ? null : array_values($ids);
    }
}
