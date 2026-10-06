<?php

namespace App\Modules\Locker\Http\Requests;

use App\Modules\Locker\Enums\ConsentPurpose;
use App\Modules\Locker\Enums\ShareTarget;
use App\Modules\Shared\Http\Validation\Formats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Share records with a registered doctor (by phone), an email address or a link. */
final class ShareRecordsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'medical_record_ids' => ['required', 'array', 'min:1', 'max:50'],
            'medical_record_ids.*' => ['required', 'uuid', 'distinct'],
            'shared_with' => ['required', 'array'],
            'shared_with.type' => ['required', Rule::enum(ShareTarget::class)],
            'shared_with.doctor_phone' => ['required_if:shared_with.type,doctor', 'prohibited_unless:shared_with.type,doctor', 'nullable', 'string', Formats::PHONE],
            'shared_with.email' => ['required_if:shared_with.type,email', 'prohibited_unless:shared_with.type,email', 'nullable', 'email:rfc', 'max:150'],
            'purpose' => ['required', Rule::enum(ConsentPurpose::class)],
            'valid_for_days' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('pathology.locker.share_max_days')],
        ];
    }

    public function days(): int
    {
        return (int) ($this->validated('valid_for_days') ?? config('pathology.locker.share_default_days'));
    }
}
