<?php

namespace App\Modules\Catalogue\Http\Requests;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Catalogue\Enums\SigningDiscipline;
use App\Modules\Catalogue\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DepartmentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $departmentId = $this->route('department') instanceof Department ? $this->route('department')->id : null;

        return [
            'name' => [
                $required, 'string', 'max:80',
                Rule::unique('departments', 'name')
                    ->where(fn ($query) => $query->where('organization_id', app(StaffContext::class)->user()->organization_id))
                    ->ignore($departmentId),
            ],
            'signing_discipline' => [$required, Rule::enum(SigningDiscipline::class)],
            'report_order' => ['sometimes', 'integer', 'between:0,1000'],
        ];
    }
}
