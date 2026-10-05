<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Http\Requests\DoctorRequest;
use App\Modules\Booking\Http\Resources\DoctorResource;
use App\Modules\Booking\Models\Doctor;
use App\Modules\Booking\Services\DoctorService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DoctorController
{
    public function __construct(private readonly DoctorService $doctors) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['phone' => 'phone', 'registration_no' => 'registration_no'])
            ->allowSorts(['name'])
            ->allowSearch(fn ($query, string $text) => $query->where('name', 'like', '%'.addcslashes($text, '%_').'%'))
            ->apply(Doctor::query());

        return CursorPage::respond($query, $request, DoctorResource::class);
    }

    public function store(DoctorRequest $request, StaffContext $staff): Response
    {
        $doctor = $this->doctors->create($staff->user()->organization_id, $request->validated());

        return ApiResponse::created(DoctorResource::make($doctor), "/api/v1/doctors/{$doctor->id}");
    }

    public function show(Doctor $doctor): Response
    {
        return EntityTag::attach(DoctorResource::make($doctor)->response(), $doctor);
    }

    public function update(DoctorRequest $request, Doctor $doctor): Response
    {
        EntityTag::assertIfMatch($request, $doctor);
        $doctor = $this->doctors->update($doctor, $request->validated());

        return EntityTag::attach(DoctorResource::make($doctor)->response(), $doctor);
    }

    public function destroy(Doctor $doctor): Response
    {
        $this->doctors->delete($doctor);

        return ApiResponse::noContent();
    }
}
