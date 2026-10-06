<?php

namespace App\Modules\Locker\Http\Resources;

use App\Modules\Locker\Models\MedicalReminder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MedicalReminder */
final class ReminderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'medical_record_id' => $this->medical_record_id,
            'remind_at' => $this->remind_at->toIso8601ZuluString(),
            'message' => $this->message,
            'status' => $this->status,
            'sent_at' => $this->sent_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at,
        ];
    }
}
