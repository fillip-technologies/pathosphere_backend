<?php

namespace App\Modules\Booking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Status updates from the phlebotomist app, with GPS on collection (spec §5.3). */
final class HomeCollectionStatusRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:en_route,collected,handed_over,rescheduled,cancelled,failed'],
            'note' => ['nullable', 'string', 'max:500', 'required_if:status,rescheduled,cancelled,failed'],
            'latitude' => ['nullable', 'decimal:0,6', 'between:-90,90'],
            'longitude' => ['nullable', 'decimal:0,6', 'between:-180,180'],
            'slot_start' => ['required_if:status,rescheduled', 'nullable', 'date', 'after:now'],
            'slot_end' => ['required_if:status,rescheduled', 'nullable', 'date', 'after:slot_start'],
        ];
    }
}
