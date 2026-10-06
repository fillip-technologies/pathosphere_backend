<?php

namespace App\Modules\Locker\Http\Requests;

use App\Modules\Locker\Enums\FamilyRelation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A dependant without a UHID yet (POST), or changes to a member (PATCH). */
final class FamilyMemberRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'relation' => [$required, Rule::enum(FamilyRelation::class)],
            'dob' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}
