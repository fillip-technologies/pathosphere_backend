<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Http\Requests\MergePatientRequest;
use App\Modules\Booking\Http\Requests\PatientRequest;
use App\Modules\Booking\Http\Resources\PatientResource;
use App\Modules\Booking\Http\Resources\PatientSummaryResource;
use App\Modules\Booking\Models\Patient;
use App\Modules\Booking\Services\PatientService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class PatientController
{
    public function __construct(private readonly PatientService $patients) {}

    /** GET /patients?q=… : search is required, the patient list is never browsable. */
    public function index(Request $request): Response
    {
        if (trim((string) $request->query('q', '')) === '') {
            throw ValidationException::withMessages(['q' => 'Search by phone, UHID, ABHA or name.']);
        }

        $query = ListQuery::from($request)
            ->allowSearch(fn ($query, string $text) => PatientService::applySearch($query, $text))
            ->allowSorts(['name', 'created_at'])
            ->apply(Patient::query());

        return CursorPage::respond($query, $request, PatientSummaryResource::class);
    }

    public function store(PatientRequest $request, StaffContext $staff): Response
    {
        $patient = $this->patients->register($staff, $request->validated());

        return ApiResponse::created(PatientResource::make($patient), "/api/v1/patients/{$patient->id}");
    }

    /** A merged record answers with the record that survived (spec §7.3). */
    public function show(Patient $patient): Response
    {
        $surviving = $this->patients->surviving($patient);

        return EntityTag::attach(PatientResource::make($surviving)->response(), $surviving);
    }

    public function update(PatientRequest $request, Patient $patient): Response
    {
        EntityTag::assertIfMatch($request, $patient);
        $patient = $this->patients->update($patient, $request->validated());

        return EntityTag::attach(PatientResource::make($patient)->response(), $patient);
    }

    public function merge(MergePatientRequest $request, Patient $patient): Response
    {
        $survivor = Patient::query()->find($request->validated('merge_into_id'))
            ?? throw ValidationException::withMessages(['merge_into_id' => 'The selected patient does not exist.']);

        return PatientResource::make($this->patients->merge($patient, $survivor))->response();
    }
}
