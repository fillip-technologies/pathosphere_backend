<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Doctor;
use App\Modules\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Referring doctors (spec §7.3). */
final class DoctorService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param  array<string, mixed>  $attributes */
    public function create(string $organizationId, array $attributes): Doctor
    {
        return DB::transaction(function () use ($organizationId, $attributes): Doctor {
            $doctor = new Doctor($attributes);
            $doctor->organization_id = $organizationId;
            $doctor->save();
            $this->auditLogger->recordCreated('doctor.create', $doctor);

            return $doctor;
        });
    }

    /** @param  array<string, mixed>  $changes */
    public function update(Doctor $doctor, array $changes): Doctor
    {
        return DB::transaction(function () use ($doctor, $changes): Doctor {
            $doctor->fill($changes)->save();
            $this->auditLogger->recordChanges('doctor.update', $doctor);

            return $doctor;
        });
    }

    public function delete(Doctor $doctor): void
    {
        DB::transaction(function () use ($doctor): void {
            $doctor->delete();
            $this->auditLogger->record('doctor.delete', $doctor);
        });
    }
}
