<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Http\Requests\AutoAssignVisitsRequest;
use App\Modules\Booking\Http\Requests\PhlebotomistRouteRequest;
use App\Modules\Booking\Http\Resources\AutoAssignmentResource;
use App\Modules\Booking\Http\Resources\PhlebotomistRouteResource;
use App\Modules\Booking\Services\CollectionRouting;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

/** Home-collection routes (spec §5.3): auto-assign by distance, and a phlebotomist's order of visits. */
final class CollectionRouteController
{
    public function __construct(
        private readonly CollectionRouting $routing,
        private readonly StaffContext $staff,
    ) {}

    /** POST /home-collection-assignments: share a branch's waiting visits of one day among its phlebotomists. */
    public function assign(AutoAssignVisitsRequest $request): Response
    {
        $assignment = $this->routing->autoAssign(
            $request->validated('branch_id'),
            CarbonImmutable::parse($request->validated('date'), 'Asia/Kolkata'),
        );

        return AutoAssignmentResource::make($assignment)->response();
    }

    /** GET /phlebotomists/{id}/route?date=: the visits still to make, in order. */
    public function show(PhlebotomistRouteRequest $request, string $phlebotomistId): Response
    {
        $date = $request->validated('date');
        $day = $date === null ? CarbonImmutable::now('Asia/Kolkata') : CarbonImmutable::parse($date, 'Asia/Kolkata');

        return PhlebotomistRouteResource::make($this->routing->route($this->staff, $phlebotomistId, $day))->response();
    }
}
