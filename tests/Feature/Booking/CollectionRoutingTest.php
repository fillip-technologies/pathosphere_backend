<?php

namespace Tests\Feature\Booking;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Network\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\TestCase;

/** Home-collection routes (spec §5.3): auto-assign by distance and each phlebotomist's order of visits. */
final class CollectionRoutingTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use RefreshDatabase;

    private User $branchAdmin;

    private User $firstPhlebotomist;

    private User $secondPhlebotomist;

    private string $patientId;

    private CarbonImmutable $tomorrow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoNetwork();
        $this->asSystem(fn () => Branch::query()->where('branch_code', 'PATPSC1')->update(['latitude' => '25.610000', 'longitude' => '85.120000']));

        $branch = ['branch_id' => $this->branchId('PATPSC1')];
        $this->branchAdmin = $this->staff(SystemRole::BranchAdmin, $branch);
        $this->firstPhlebotomist = $this->staff(SystemRole::Phlebotomist, $branch);
        $this->secondPhlebotomist = $this->staff(SystemRole::Phlebotomist, $branch);
        $this->tomorrow = CarbonImmutable::now('Asia/Kolkata')->addDay()->startOfDay();

        $this->actingAsStaff($this->staff(SystemRole::FrontDesk, $branch));
        $this->patientId = $this->registerPatient()['id'];
    }

    public function test_a_days_visits_are_shared_by_distance_and_each_phlebotomist_gets_an_ordered_route(): void
    {
        $farEast = $this->bookVisit(['latitude' => '25.600000', 'longitude' => '85.200000'], 7, 0, 7, 30);
        $boringRoad = $this->bookVisit(['latitude' => '25.615000', 'longitude' => '85.130000'], 7, 0, 8, 0);
        $nextDoor = $this->bookVisit(['latitude' => '25.620000', 'longitude' => '85.135000'], 7, 30, 9, 0);
        $noPin = $this->bookVisit([], 10, 0, 11, 0);

        $assignment = $this->actingAsStaff($this->branchAdmin)
            ->postJson('/api/v1/home-collection-assignments', ['branch_id' => $this->branchId('PATPSC1'), 'date' => $this->tomorrow->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.unassigned', [['home_collection_id' => $noPin, 'reason' => 'no_location']])
            ->json('data.assigned');

        $assignedTo = array_column($assignment, 'phlebotomist_id', 'home_collection_id');
        $this->assertCount(3, $assignedTo);
        $this->assertNotSame($assignedTo[$farEast], $assignedTo[$boringRoad]);
        $this->assertSame($assignedTo[$boringRoad], $assignedTo[$nextDoor]);

        $this->getJson("/api/v1/home-collections/{$nextDoor}")->assertJsonPath('data.status', 'assigned');

        // Running it again changes nothing: assigned visits stay where they are.
        $this->postJson('/api/v1/home-collection-assignments', ['branch_id' => $this->branchId('PATPSC1'), 'date' => $this->tomorrow->toDateString()])
            ->assertOk()->assertJsonPath('data.assigned', []);

        $nearbyPhlebotomist = $assignedTo[$boringRoad] === $this->firstPhlebotomist->id ? $this->firstPhlebotomist : $this->secondPhlebotomist;
        $route = $this->actingAsStaff($nearbyPhlebotomist)
            ->getJson("/api/v1/phlebotomists/{$nearbyPhlebotomist->id}/route?date={$this->tomorrow->toDateString()}")
            ->assertOk()
            ->assertJsonPath('data.start.latitude', '25.610000')
            ->assertJsonPath('data.stops.0.home_collection_id', $boringRoad)
            ->assertJsonPath('data.stops.0.estimated_start', $this->tomorrow->setTime(7, 0)->utc()->toIso8601ZuluString())
            ->assertJsonPath('data.stops.1.home_collection_id', $nextDoor)
            ->assertJsonPath('data.stops.1.on_time', true)
            ->json('data');
        $this->assertSame($route['stops'][0]['leg_distance_m'] + $route['stops'][1]['leg_distance_m'], $route['total_distance_m']);
    }

    public function test_routes_are_private_to_the_phlebotomist_and_the_branch(): void
    {
        $this->bookVisit(['latitude' => '25.615000', 'longitude' => '85.130000'], 7, 0, 8, 0);
        $date = $this->tomorrow->toDateString();

        // A phlebotomist sees only their own route.
        $this->actingAsStaff($this->secondPhlebotomist)
            ->getJson("/api/v1/phlebotomists/{$this->firstPhlebotomist->id}/route?date={$date}")
            ->assertNotFound();
        $this->getJson("/api/v1/phlebotomists/{$this->secondPhlebotomist->id}/route")
            ->assertOk()->assertJsonPath('data.stops', []);
        $this->postJson('/api/v1/home-collection-assignments', ['branch_id' => $this->branchId('PATPSC1'), 'date' => $date])
            ->assertForbidden();

        // Another branch's manager cannot plan here or read these routes.
        $elsewhere = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('PATPSC2')]);
        $this->actingAsStaff($elsewhere)
            ->postJson('/api/v1/home-collection-assignments', ['branch_id' => $this->branchId('PATPSC1'), 'date' => $date])
            ->assertNotFound();
        $this->getJson("/api/v1/phlebotomists/{$this->firstPhlebotomist->id}/route?date={$date}")->assertNotFound();

        $this->actingAsStaff($this->branchAdmin)
            ->postJson('/api/v1/home-collection-assignments', ['branch_id' => $this->branchId('PATPSC1'), 'date' => '07-10-2026'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'date');
    }

    public function test_a_pin_must_have_both_coordinates(): void
    {
        $this->bookOrder($this->patientId, 'PATPSC1', [
            'order_source' => 'home_collection',
            'items' => [$this->testItem('CBC')],
            'home_collection' => [...$this->visitDetails(7, 0, 8, 0), 'latitude' => '25.615000'],
        ])->assertStatus(422)->assertJsonPath('error.details.0.field', 'home_collection.longitude');
    }

    /**
     * Books a home-collection order for tomorrow at Boring Road PSC.
     *
     * @param  array<string, string>  $pin
     * @return string the visit ID
     */
    private function bookVisit(array $pin, int $fromHour, int $fromMinute, int $toHour, int $toMinute): string
    {
        return $this->bookOrder($this->patientId, 'PATPSC1', [
            'order_source' => 'home_collection',
            'items' => [$this->testItem('CBC')],
            'home_collection' => [...$this->visitDetails($fromHour, $fromMinute, $toHour, $toMinute), ...$pin],
        ])->assertCreated()->json('data.home_collection.id');
    }

    /** @return array<string, string> */
    private function visitDetails(int $fromHour, int $fromMinute, int $toHour, int $toMinute): array
    {
        return [
            'address' => '12 Boring Road, Patna',
            'pincode' => '800001',
            'slot_start' => $this->tomorrow->setTime($fromHour, $fromMinute)->toIso8601String(),
            'slot_end' => $this->tomorrow->setTime($toHour, $toMinute)->toIso8601String(),
        ];
    }
}
