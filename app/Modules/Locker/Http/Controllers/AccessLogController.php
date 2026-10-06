<?php

namespace App\Modules\Locker\Http\Controllers;

use App\Modules\Auth\Services\StaffDirectory;
use App\Modules\Booking\Services\PeopleDirectory;
use App\Modules\Locker\Enums\AccessActorType;
use App\Modules\Locker\Models\MedicalRecord;
use App\Modules\Locker\Models\RecordAccessLog;
use App\Modules\Locker\Services\PatientViewer;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /me/record-access-logs: who opened the profile's records, newest
 * first (spec §10 DPDP: the patient's right to know). Doctors and staff are
 * named; share links and signed report links are shown as such.
 */
final class AccessLogController
{
    public function __construct(private readonly PatientViewer $viewer) {}

    public function index(Request $request, PeopleDirectory $people, StaffDirectory $staff): Response
    {
        $query = RecordAccessLog::query()
            ->whereIn('medical_record_id', MedicalRecord::query()->whereIn('patient_id', $this->viewer->patientIds())->select('id'))
            ->orderByDesc('accessed_at')
            ->orderByDesc('id');
        ListQuery::from($request)->allowFilters(['medical_record_id' => 'medical_record_id'])->apply($query);

        return CursorPage::respondWith($query, $request, function (Collection $logs) use ($people, $staff): array {
            $doctorNames = array_map(
                fn ($doctor) => $doctor->name,
                $people->doctors($logs->where('actor_type', AccessActorType::Doctor)->pluck('actor_id')->filter()->values()->all()),
            );
            $staffNames = $staff->names($logs->where('actor_type', AccessActorType::Staff)->pluck('actor_id')->filter()->values()->all());

            return $logs->map(fn (RecordAccessLog $log) => [
                'id' => $log->id,
                'medical_record_id' => $log->medical_record_id,
                'actor_type' => $log->actor_type,
                'actor_name' => match ($log->actor_type) {
                    AccessActorType::Doctor => $doctorNames[$log->actor_id] ?? null,
                    AccessActorType::Staff => $staffNames[$log->actor_id] ?? null,
                    default => null,
                },
                'action' => $log->action,
                'accessed_at' => $log->accessed_at->toIso8601ZuluString(),
            ])->values()->all();
        });
    }
}
