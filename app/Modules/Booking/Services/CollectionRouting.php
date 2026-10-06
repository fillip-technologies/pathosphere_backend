<?php

namespace App\Modules\Booking\Services;

use App\Modules\Auth\Permissions\Permission;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Auth\Services\StaffDirectory;
use App\Modules\Booking\Domain\CollectionRoutePlanner;
use App\Modules\Booking\Domain\GeoPoint;
use App\Modules\Booking\Domain\RouteStop;
use App\Modules\Booking\Domain\TravelAssumptions;
use App\Modules\Booking\Enums\HomeCollectionStatus;
use App\Modules\Booking\Models\HomeCollection;
use App\Modules\Booking\StateMachines\HomeCollectionStateMachine;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Errors\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Home-collection routes (spec §5.3): a day's waiting visits shared among the
 * branch's phlebotomists by distance, and the order a phlebotomist makes
 * their visits in. Days are business days in India.
 */
final class CollectionRouting
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    /** Visits waiting for a phlebotomist. */
    private const AWAITING = [HomeCollectionStatus::Scheduled, HomeCollectionStatus::Rescheduled];

    /** Visits on a phlebotomist's route that are still to be made. */
    private const ON_ROUTE = [HomeCollectionStatus::Assigned, HomeCollectionStatus::EnRoute];

    public function __construct(
        private readonly NetworkDirectory $network,
        private readonly StaffDirectory $staffDirectory,
        private readonly HomeCollectionStateMachine $stateMachine,
    ) {}

    /**
     * Assigns the branch's waiting visits of the day. Visits already
     * assigned stay with their phlebotomist and shape the routes; managers
     * can still reassign any visit by hand.
     */
    public function autoAssign(string $branchId, CarbonImmutable $date): AutoAssignment
    {
        $branch = $this->network->branchLocation($branchId) ?? throw new DomainError(ErrorCode::NOT_FOUND, 'The requested resource was not found.', 404);
        $start = GeoPoint::fromColumns($branch->latitude, $branch->longitude);
        $phlebotomistIds = $this->staffDirectory->activeStaffWithPermissionAt($branchId, Permission::ViewAssignedCollections);

        return DB::transaction(function () use ($branchId, $date, $start, $phlebotomistIds): AutoAssignment {
            // Locking the day's visits makes two managers planning at once take turns.
            $visits = $this->onDay(HomeCollection::query()->where('branch_id', $branchId), $date)
                ->whereIn('status', [...self::AWAITING, ...self::ON_ROUTE])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $currentRoutes = array_fill_keys($phlebotomistIds, []);
            $waiting = [];
            foreach ($visits as $visit) {
                if (in_array($visit->status, self::AWAITING, true)) {
                    $waiting[] = $this->stop($visit);
                } elseif (array_key_exists((string) $visit->phlebotomist_id, $currentRoutes)) {
                    $currentRoutes[(string) $visit->phlebotomist_id][] = $this->stop($visit);
                }
            }

            $plan = $this->planner()->assign($start, $currentRoutes, $waiting);

            foreach ($plan->assigned as $assignment) {
                $this->stateMachine->transition($visits[$assignment->visitId], HomeCollectionStatus::Assigned, ['phlebotomist_id' => $assignment->phlebotomistId]);
            }

            return new AutoAssignment($branchId, $date, $plan);
        });
    }

    /**
     * A phlebotomist's visits still to make that day, in the order to make
     * them. Phlebotomists see only their own route; managers any phlebotomist
     * they can see.
     */
    public function route(StaffContext $staff, string $phlebotomistId, CarbonImmutable $date): PhlebotomistRoute
    {
        $isOwnRoute = $phlebotomistId === $staff->user()->id;

        if (! $isOwnRoute && ! $staff->has(Permission::ManageHomeCollection)) {
            throw new DomainError(ErrorCode::NOT_FOUND, 'The requested resource was not found.', 404);
        }

        $branchId = $this->staffDirectory->visibleStaffBranchId($phlebotomistId)
            ?? throw new DomainError(ErrorCode::NOT_FOUND, 'The requested resource was not found.', 404);

        /** @var Collection<string, HomeCollection> $visits */
        $visits = $this->onDay(HomeCollection::query()->where('phlebotomist_id', $phlebotomistId), $date)
            ->whereIn('status', self::ON_ROUTE)
            ->get()
            ->keyBy('id');

        $branch = $this->network->branchLocation($branchId);
        $start = $branch === null ? null : GeoPoint::fromColumns($branch->latitude, $branch->longitude);
        $planned = $this->planner()->sequence($start, $visits->map(fn (HomeCollection $visit) => $this->stop($visit))->values()->all());

        return new PhlebotomistRoute($phlebotomistId, $date, $branch, $planned, $visits->all());
    }

    /**
     * @param  Builder<HomeCollection>  $query
     * @return Builder<HomeCollection>
     */
    private function onDay(Builder $query, CarbonImmutable $date): Builder
    {
        $fromUtc = CarbonImmutable::parse($date->toDateString(), self::BUSINESS_TIMEZONE)->utc();

        return $query->where('slot_start', '>=', $fromUtc)->where('slot_start', '<', $fromUtc->addDay());
    }

    private function stop(HomeCollection $visit): RouteStop
    {
        return new RouteStop(
            $visit->id,
            GeoPoint::fromColumns($visit->latitude, $visit->longitude),
            $visit->slot_start->getTimestamp(),
            $visit->slot_end->getTimestamp(),
        );
    }

    private function planner(): CollectionRoutePlanner
    {
        return new CollectionRoutePlanner(new TravelAssumptions(
            (int) config('pathology.home_collection.average_speed_kmh'),
            (int) config('pathology.home_collection.visit_minutes'),
            (int) config('pathology.home_collection.road_factor_percent'),
        ));
    }
}
