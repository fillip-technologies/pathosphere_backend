<?php

namespace Tests\Unit\Booking;

use App\Modules\Booking\Domain\CollectionRoutePlanner;
use App\Modules\Booking\Domain\GeoPoint;
use App\Modules\Booking\Domain\PlannedAssignment;
use App\Modules\Booking\Domain\PlannedStop;
use App\Modules\Booking\Domain\RoadDistance;
use App\Modules\Booking\Domain\RouteStop;
use App\Modules\Booking\Domain\TravelAssumptions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Home-collection routing (spec §5.3) in Patna: 20 km/h, 15 minutes a visit, roads 30% longer. */
final class CollectionRoutePlannerTest extends TestCase
{
    private const DAY = 1_800_000_000;

    private GeoPoint $branch;

    private CollectionRoutePlanner $planner;

    protected function setUp(): void
    {
        $this->branch = $this->point('25.610000', '85.120000');
        $this->planner = new CollectionRoutePlanner(new TravelAssumptions(20, 15, 130));
    }

    public function test_coordinates_are_read_exactly_and_bad_ones_are_refused(): void
    {
        $point = $this->point('-0.5', '85.144001');

        $this->assertSame(-500_000, $point->latitudeMicro);
        $this->assertSame(85_144_001, $point->longitudeMicro);
        $this->assertNull(GeoPoint::fromColumns('25.6', null));

        $this->expectException(InvalidArgumentException::class);
        GeoPoint::fromColumns('91.0', '85.0');
    }

    public function test_road_distance_is_the_great_circle_stretched_by_the_road_factor(): void
    {
        $equator = $this->point('0', '0');
        $oneDegreeNorth = $this->point('1', '0');

        $this->assertEqualsWithDelta(111_195, RoadDistance::metres($equator, $oneDegreeNorth, 100), 1);
        $this->assertEqualsWithDelta(144_553, RoadDistance::metres($equator, $oneDegreeNorth, 130), 1);
        $this->assertSame(0, RoadDistance::metres($equator, null, 130));
    }

    public function test_nearby_visits_go_to_one_phlebotomist_and_a_far_one_to_another(): void
    {
        $farEast = new RouteStop('far-east', $this->point('25.600000', '85.200000'), $this->at(7, 0), $this->at(7, 30));
        $boringRoad = new RouteStop('boring-road', $this->point('25.615000', '85.130000'), $this->at(7, 0), $this->at(8, 0));
        $nextDoor = new RouteStop('next-door', $this->point('25.620000', '85.135000'), $this->at(7, 30), $this->at(9, 0));

        $plan = $this->planner->assign($this->branch, ['p1' => [], 'p2' => []], [$nextDoor, $boringRoad, $farEast]);

        $this->assertSame(
            ['far-east' => 'p1', 'boring-road' => 'p2', 'next-door' => 'p2'],
            array_column(array_map(fn (PlannedAssignment $assignment) => [$assignment->visitId, $assignment->phlebotomistId], $plan->assigned), 1, 0),
        );
        $this->assertSame([], $plan->unassigned);
        $this->assertLessThan(1_500, $plan->assigned[2]->addedMetres);
    }

    public function test_visits_already_assigned_shape_the_day_and_fully_booked_slots_are_left_for_a_person(): void
    {
        $taken = new RouteStop('taken', $this->point('25.615000', '85.130000'), $this->at(7, 0), $this->at(7, 15));
        $sameSlotFarAway = new RouteStop('same-slot', $this->point('25.600000', '85.200000'), $this->at(7, 0), $this->at(7, 15));
        $unplaced = new RouteStop('unplaced', null, $this->at(9, 0), $this->at(10, 0));
        $later = new RouteStop('later', $this->point('25.620000', '85.135000'), $this->at(9, 0), $this->at(10, 0));

        $plan = $this->planner->assign($this->branch, ['p1' => [$taken]], [$sameSlotFarAway, $unplaced, $later]);

        $this->assertSame(['later'], array_map(fn (PlannedAssignment $assignment) => $assignment->visitId, $plan->assigned));
        $this->assertSame([
            'same-slot' => CollectionRoutePlanner::NO_PHLEBOTOMIST_FREE,
            'unplaced' => CollectionRoutePlanner::NO_LOCATION,
        ], $plan->unassigned);
    }

    public function test_an_overbooked_phlebotomist_takes_nothing_more(): void
    {
        $first = new RouteStop('first', $this->point('25.615000', '85.130000'), $this->at(7, 0), $this->at(7, 5));
        $clash = new RouteStop('clash', $this->point('25.600000', '85.200000'), $this->at(7, 0), $this->at(7, 5));
        $evening = new RouteStop('evening', $this->point('25.615000', '85.130000'), $this->at(18, 0), $this->at(19, 0));

        $plan = $this->planner->assign($this->branch, ['p1' => [$first, $clash]], [$evening]);

        $this->assertSame([], $plan->assigned);
        $this->assertSame(['evening' => CollectionRoutePlanner::NO_PHLEBOTOMIST_FREE], $plan->unassigned);
    }

    public function test_a_day_is_ordered_to_save_distance_within_the_slots(): void
    {
        $far = new RouteStop('far', $this->point('25.600000', '85.200000'), $this->at(8, 0), $this->at(11, 0));
        $near = new RouteStop('near', $this->point('25.615000', '85.130000'), $this->at(8, 0), $this->at(11, 0));

        $route = $this->planner->sequence($this->branch, [$far, $near]);

        $this->assertSame(['near', 'far'], $this->visitIds($route));
        $this->assertSame($this->at(8, 0), $route[0]->visitStartsAt);
        $this->assertGreaterThan($this->at(8, 15), $route[1]->visitStartsAt);
        $this->assertTrue($route[1]->onTime);
    }

    public function test_visits_that_cannot_all_be_reached_are_put_last_and_marked_late(): void
    {
        $first = new RouteStop('first', $this->point('25.615000', '85.130000'), $this->at(7, 0), $this->at(7, 5));
        $clash = new RouteStop('clash', $this->point('25.600000', '85.200000'), $this->at(7, 0), $this->at(7, 5));
        $later = new RouteStop('later', $this->point('25.620000', '85.135000'), $this->at(9, 0), $this->at(10, 0));

        $route = $this->planner->sequence($this->branch, [$clash, $later, $first]);

        // Same slot: the tie goes to the visit ID, so 'clash' keeps its place and 'first' runs late.
        $this->assertSame(['clash', 'later', 'first'], $this->visitIds($route));
        $this->assertSame([true, true, false], array_map(fn (PlannedStop $stop) => $stop->onTime, $route));
    }

    private function point(string $latitude, string $longitude): GeoPoint
    {
        return GeoPoint::fromColumns($latitude, $longitude) ?? throw new InvalidArgumentException('missing point');
    }

    private function at(int $hour, int $minute): int
    {
        return self::DAY + $hour * 3600 + $minute * 60;
    }

    /**
     * @param  list<PlannedStop>  $route
     * @return list<string>
     */
    private function visitIds(array $route): array
    {
        return array_map(fn (PlannedStop $stop) => $stop->stop->visitId, $route);
    }
}
