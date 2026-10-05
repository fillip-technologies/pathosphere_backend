<?php

namespace App\Modules\Booking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignPhlebotomistRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['phlebotomist_id' => ['required', 'uuid']];
    }
}
