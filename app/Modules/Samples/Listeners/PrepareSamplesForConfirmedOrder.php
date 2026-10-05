<?php

namespace App\Modules\Samples\Listeners;

use App\Modules\Booking\Events\OrderConfirmed;
use App\Modules\Samples\Services\SampleCollectionService;
use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Generates barcodes as soon as an order is confirmed (spec §9
 * OrderConfirmed), so labels are ready when the patient reaches the
 * collection chair. The desk can ask again; nothing is created twice.
 */
final class PrepareSamplesForConfirmedOrder implements ShouldQueue
{
    public function __construct(private readonly SampleCollectionService $collection) {}

    public function handle(OrderConfirmed $event): void
    {
        WithSystemScope::run($event->organizationId, function () use ($event): void {
            try {
                $this->collection->prepareForOrder($event->orderId);
            } catch (DomainError $closedOrder) {
                // Cancelled before the queue got to it: nothing to draw.
                if ($closedOrder->errorCode !== 'ORDER_NOT_OPEN_FOR_SAMPLES') {
                    throw $closedOrder;
                }
            }
        });
    }
}
