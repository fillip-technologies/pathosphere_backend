<?php

namespace App\Modules\Locker\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReminderRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'remind_at' => ['required', 'date', 'after:now'],
            'message' => ['required', 'string', 'max:500'],
            'medical_record_id' => ['nullable', 'uuid'],
        ];
    }
}
