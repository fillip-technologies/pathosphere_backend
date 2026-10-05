<?php

namespace App\Modules\Booking\Services;

use App\Modules\Auth\Permissions\Permission;
use App\Modules\Auth\Services\StaffContext;
use App\Modules\Auth\Services\StaffDirectory;
use App\Modules\Booking\Contracts\Geocoder;
use App\Modules\Booking\Enums\HomeCollectionStatus;
use App\Modules\Booking\Errors\BookingError;
use App\Modules\Booking\Models\HomeCollection;
use App\Modules\Booking\Models\Order;
use App\Modules\Booking\StateMachines\HomeCollectionStateMachine;
use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Errors\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Home sample collection (spec §5.3). */
final class HomeCollectionService
{
    public function __construct(
        private readonly Geocoder $geocoder,
        private readonly StaffDirectory $staffDirectory,
        private readonly HomeCollectionStateMachine $stateMachine,
    ) {}

    public function createForOrder(Order $order, HomeVisitRequest $visit): HomeCollection
    {
        $coordinates = $this->geocoder->geocode($visit->address, $visit->pincode);

        $collection = new HomeCollection([
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'address' => $visit->address,
            'pincode' => $visit->pincode,
            'latitude' => $coordinates['latitude'] ?? null,
            'longitude' => $coordinates['longitude'] ?? null,
            'slot_start' => $visit->slotStart,
            'slot_end' => $visit->slotEnd,
            'collection_charge' => $visit->collectionCharge,
            'status' => HomeCollectionStatus::Scheduled,
        ]);
        $collection->organization_id = $order->organization_id;
        $collection->save();

        return $collection;
    }

    /** Assigns (or reassigns) a phlebotomist from the visit's branch. */
    public function assign(HomeCollection $collection, string $phlebotomistId): HomeCollection
    {
        if (! $this->staffDirectory->isActiveStaffWithPermissionAt($phlebotomistId, $collection->branch_id, Permission::ViewAssignedCollections)) {
            throw BookingError::phlebotomistNotAvailable();
        }

        return DB::transaction(function () use ($collection, $phlebotomistId): HomeCollection {
            if ($collection->status === HomeCollectionStatus::Assigned) {
                $collection->update(['phlebotomist_id' => $phlebotomistId]);

                return $collection;
            }

            $this->stateMachine->transition($collection, HomeCollectionStatus::Assigned, ['phlebotomist_id' => $phlebotomistId]);

            return $collection;
        });
    }

    /**
     * Status updates from the phlebotomist app. A phlebotomist may only move
     * their own visits; collection needs GPS proof (spec §7.5).
     */
    public function updateStatus(
        StaffContext $staff,
        HomeCollection $collection,
        HomeCollectionStatus $status,
        ?string $note,
        ?string $latitude,
        ?string $longitude,
        ?CarbonImmutable $newSlotStart,
        ?CarbonImmutable $newSlotEnd,
    ): HomeCollection {
        $isManager = $staff->has(Permission::ManageHomeCollection);

        if (! $isManager && $collection->phlebotomist_id !== $staff->user()->id) {
            throw new DomainError(ErrorCode::NOT_FOUND, 'The requested resource was not found.', 404);
        }

        $attributes = ['status_note' => $note];

        if ($status === HomeCollectionStatus::Collected) {
            if ($latitude === null || $longitude === null) {
                throw BookingError::gpsRequired();
            }

            $attributes += ['collected_at' => now(), 'collected_lat' => $latitude, 'collected_lng' => $longitude];
        }

        if ($status === HomeCollectionStatus::Rescheduled) {
            $attributes += ['slot_start' => $newSlotStart, 'slot_end' => $newSlotEnd];
        }

        $this->stateMachine->transition($collection, $status, $attributes);

        return $collection;
    }
}
