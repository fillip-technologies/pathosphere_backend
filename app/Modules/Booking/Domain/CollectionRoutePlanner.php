<?php

namespace App\Modules\Booking\Domain;

/**
 * Home-collection routing (spec §5.3 "auto-assign by distance"), pure.
 *
 * Cheapest insertion with time windows: visits are taken in order of slot
 * end, and each goes to the phlebotomist and place in their day where it adds
 * the least road distance while every visit on that route is still reached
 * within its slot. Routes start at the branch. A visit without a location
 * adds no known distance but its slot still counts.
 */
final class CollectionRoutePlanner
{
    public const NO_LOCATION = 'no_location';

    public const NO_PHLEBOTOMIST_FREE = 'no_phlebotomist_free';

    public function __construct(private readonly TravelAssumptions $travel) {}

    /**
     * The order one phlebotomist should make their visits in. Visits that
     * cannot all be reached in their slots (overbooked by hand) are put last
     * in slot order and marked late.
     *
     * @param  list<RouteStop>  $stops
     * @return list<PlannedStop>
     */
    public function sequence(?GeoPoint $start, array $stops): array
    {
        [$route, $late] = $this->build($start, $stops);

        return $this->simulate($start, [...$route, ...$late]);
    }

    /**
     * Shares new visits among phlebotomists. A phlebotomist whose current
     * visits already cannot all be reached takes nothing more.
     *
     * @param  array<string, list<RouteStop>>  $currentByPhlebotomist  every candidate, with their visits already assigned that day
     * @param  list<RouteStop>  $newVisits
     */
    public function assign(?GeoPoint $start, array $currentByPhlebotomist, array $newVisits): AssignmentPlan
    {
        $routes = [];
        foreach ($currentByPhlebotomist as $phlebotomistId => $stops) {
            [$route, $late] = $this->build($start, $stops);

            if ($late === []) {
                $routes[(string) $phlebotomistId] = $route;
            }
        }

        $assigned = [];
        $unassigned = [];

        foreach ($this->bySlot($newVisits) as $visit) {
            if ($visit->point === null) {
                $unassigned[$visit->visitId] = self::NO_LOCATION;

                continue;
            }

            $best = null;
            foreach ($routes as $phlebotomistId => $route) {
                $insertion = $this->cheapestInsertion($start, $route, $visit);

                if ($insertion !== null && ($best === null || $this->isBetter($insertion, count($route), $phlebotomistId, $best))) {
                    $best = ['route' => $insertion['route'], 'added' => $insertion['added'], 'size' => count($route), 'id' => $phlebotomistId];
                }
            }

            if ($best === null) {
                $unassigned[$visit->visitId] = self::NO_PHLEBOTOMIST_FREE;

                continue;
            }

            $routes[$best['id']] = $best['route'];
            $assigned[] = new PlannedAssignment($visit->visitId, $best['id'], $best['added']);
        }

        return new AssignmentPlan($assigned, $unassigned);
    }

    /**
     * @param  list<RouteStop>  $stops
     * @return array{list<RouteStop>, list<RouteStop>} the reachable route and the visits left over
     */
    private function build(?GeoPoint $start, array $stops): array
    {
        $route = [];
        $late = [];

        foreach ($this->bySlot($stops) as $stop) {
            $insertion = $this->cheapestInsertion($start, $route, $stop);

            if ($insertion === null) {
                $late[] = $stop;

                continue;
            }

            $route = $insertion['route'];
        }

        usort($late, fn (RouteStop $a, RouteStop $b) => [$a->slotStart, $a->slotEnd, $a->visitId] <=> [$b->slotStart, $b->slotEnd, $b->visitId]);

        return [$route, $late];
    }

    /**
     * @param  list<RouteStop>  $route  reachable as it is
     * @return array{route: list<RouteStop>, added: int}|null null when no place keeps every visit on time
     */
    private function cheapestInsertion(?GeoPoint $start, array $route, RouteStop $stop): ?array
    {
        $currentMetres = $this->totalMetres($this->simulate($start, $route));
        $best = null;

        for ($position = 0; $position <= count($route); $position++) {
            $candidate = [...array_slice($route, 0, $position), $stop, ...array_slice($route, $position)];
            $planned = $this->simulate($start, $candidate);

            if (! $this->allOnTime($planned)) {
                continue;
            }

            $added = $this->totalMetres($planned) - $currentMetres;

            if ($best === null || $added < $best['added']) {
                $best = ['route' => $candidate, 'added' => $added];
            }
        }

        return $best;
    }

    /**
     * Walks the route: the first visit starts at its slot (the phlebotomist
     * leaves the branch in time); each later one when the phlebotomist gets
     * there, or at its slot start if they arrive early.
     *
     * @param  list<RouteStop>  $route
     * @return list<PlannedStop>
     */
    private function simulate(?GeoPoint $start, array $route): array
    {
        $planned = [];
        $position = $start;
        $freeAt = null;

        foreach ($route as $stop) {
            $legMetres = $this->travel->metres($position, $stop->point);
            $arrival = $freeAt === null ? $stop->slotStart : $freeAt + $this->travel->travelSeconds($legMetres);
            $visitStartsAt = max($arrival, $stop->slotStart);

            $planned[] = new PlannedStop($stop, $legMetres, $visitStartsAt, $visitStartsAt <= $stop->slotEnd);

            $freeAt = $visitStartsAt + $this->travel->visitSeconds();
            $position = $stop->point ?? $position;
        }

        return $planned;
    }

    /**
     * @param  array{added: int}  $insertion
     * @param  array{added: int, size: int, id: string}  $best
     */
    private function isBetter(array $insertion, int $routeSize, string $phlebotomistId, array $best): bool
    {
        // Least extra distance, then the lighter day, then a stable order.
        return [$insertion['added'], $routeSize, $phlebotomistId] < [$best['added'], $best['size'], $best['id']];
    }

    /**
     * @param  list<RouteStop>  $stops
     * @return list<RouteStop>
     */
    private function bySlot(array $stops): array
    {
        usort($stops, fn (RouteStop $a, RouteStop $b) => [$a->slotEnd, $a->slotStart, $a->visitId] <=> [$b->slotEnd, $b->slotStart, $b->visitId]);

        return $stops;
    }

    /** @param  list<PlannedStop>  $planned */
    private function totalMetres(array $planned): int
    {
        return array_sum(array_map(fn (PlannedStop $stop) => $stop->legMetres, $planned));
    }

    /** @param  list<PlannedStop>  $planned */
    private function allOnTime(array $planned): bool
    {
        foreach ($planned as $stop) {
            if (! $stop->onTime) {
                return false;
            }
        }

        return true;
    }
}
