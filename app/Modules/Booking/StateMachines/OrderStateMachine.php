<?php

namespace App\Modules\Booking\StateMachines;

use App\Modules\Booking\Contracts\PartnerCharge;
use App\Modules\Booking\Contracts\PartnerChargePolicy;
use App\Modules\Booking\Enums\OrderStatus;
use App\Modules\Booking\Events\OrderConfirmed;
use App\Modules\Booking\Models\Order;
use App\Modules\Booking\Models\OrderItem;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\StateMachines\StateMachine;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Order lifecycle (spec §5.2). Confirming posts the partner charge; cancelling
 * reverses it, both inside the same transaction as the status change.
 */
final class OrderStateMachine extends StateMachine
{
    public function __construct(
        AuditLogger $auditLogger,
        private readonly PartnerChargePolicy $partnerCharges,
    ) {
        parent::__construct($auditLogger);
    }

    protected function transitions(): array
    {
        return [
            'draft' => ['confirmed', 'cancelled'],
            'confirmed' => ['in_progress', 'cancelled'],
            'in_progress' => ['partially_reported', 'completed'],
            'partially_reported' => ['completed'],
        ];
    }

    protected function entityName(): string
    {
        return 'order';
    }

    protected function afterTransition(Model $entity, string $from, BackedEnum $to): void
    {
        /** @var Order $entity */
        if ($to === OrderStatus::Confirmed) {
            $this->partnerCharges->chargeForConfirmedOrder($this->partnerCharge($entity));
            event(new OrderConfirmed($entity->id, $entity->organization_id));
        }

        // Only a confirmed order was charged, so only it is reversed.
        if ($to === OrderStatus::Cancelled && $from === OrderStatus::Confirmed->value) {
            $this->partnerCharges->reverseForCancelledOrder($this->partnerCharge($entity));
        }
    }

    private function partnerCharge(Order $order): PartnerCharge
    {
        $pricedLines = $order->items()->whereNull('parent_item_id')->get();

        return new PartnerCharge(
            $order->id,
            $order->organization_id,
            $order->franchise_id,
            $order->b2b_client_id,
            $pricedLines->mapWithKeys(fn (OrderItem $item) => [$item->id => $item->partner_price])->all(),
        );
    }
}
