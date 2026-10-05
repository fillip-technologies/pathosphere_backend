<?php

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Auth\Permissions\Permission;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Enums\HomeCollectionStatus;
use App\Modules\Booking\Http\Requests\AssignPhlebotomistRequest;
use App\Modules\Booking\Http\Requests\HomeCollectionStatusRequest;
use App\Modules\Booking\Http\Resources\HomeCollectionResource;
use App\Modules\Booking\Models\HomeCollection;
use App\Modules\Booking\Services\HomeCollectionService;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Home visits (spec §5.3). Branch managers see the branch's visits;
 * phlebotomists see only their own.
 */
final class HomeCollectionController
{
    public function __construct(
        private readonly HomeCollectionService $homeCollections,
        private readonly StaffContext $staff,
    ) {}

    public function index(Request $request): Response
    {
        $visits = HomeCollection::query();

        if (! $this->staff->has(Permission::ManageHomeCollection)) {
            $visits->where('phlebotomist_id', $this->staff->user()->id);
        }

        $query = ListQuery::from($request)
            ->allowFilters([
                'status' => 'status',
                'phlebotomist_id' => 'phlebotomist_id',
                'date' => fn ($query, string $date) => $query->whereDate('slot_start', $date),
            ])
            ->allowSorts(['slot_start'])
            ->apply($visits);

        return CursorPage::respond($query, $request, HomeCollectionResource::class);
    }

    public function show(HomeCollection $homeCollection): Response
    {
        $this->assertCanSee($homeCollection);

        return HomeCollectionResource::make($homeCollection)->response();
    }

    public function assign(AssignPhlebotomistRequest $request, HomeCollection $homeCollection): Response
    {
        $visit = $this->homeCollections->assign($homeCollection, $request->validated('phlebotomist_id'));

        return HomeCollectionResource::make($visit)->response();
    }

    public function updateStatus(HomeCollectionStatusRequest $request, HomeCollection $homeCollection): Response
    {
        $slotStart = $request->validated('slot_start');
        $slotEnd = $request->validated('slot_end');

        $visit = $this->homeCollections->updateStatus(
            $this->staff,
            $homeCollection,
            HomeCollectionStatus::from($request->validated('status')),
            $request->validated('note'),
            $request->validated('latitude') === null ? null : (string) $request->validated('latitude'),
            $request->validated('longitude') === null ? null : (string) $request->validated('longitude'),
            $slotStart === null ? null : CarbonImmutable::parse($slotStart)->utc(),
            $slotEnd === null ? null : CarbonImmutable::parse($slotEnd)->utc(),
        );

        return HomeCollectionResource::make($visit)->response();
    }

    private function assertCanSee(HomeCollection $visit): void
    {
        $isOwnVisit = $visit->phlebotomist_id === $this->staff->user()->id;

        abort_unless($this->staff->has(Permission::ManageHomeCollection) || $isOwnVisit, 404);
    }
}
