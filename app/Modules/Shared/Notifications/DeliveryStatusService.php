<?php

namespace App\Modules\Shared\Notifications;

use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;

/**
 * Applies delivery receipts to `notifications.status` (spec §9 rule 5).
 * Receipts arrive out of order and repeat, so a status only moves forward:
 * sent → delivered → read, or to failed before delivery.
 */
final class DeliveryStatusService
{
    private const PROGRESS = [
        'queued' => 0,
        'sent' => 1,
        'failed' => 2,
        'delivered' => 2,
        'read' => 3,
    ];

    public function __construct(private readonly CurrentScope $currentScope) {}

    /**
     * @param  list<DeliveryReport>  $reports
     * @return int notifications updated
     */
    public function apply(array $reports): int
    {
        return $this->currentScope->runAs(ScopeContext::system(), function () use ($reports): int {
            $updated = 0;

            foreach ($reports as $report) {
                $notification = Notification::query()->where('provider_message_id', $report->providerMessageId)->first();

                if ($notification === null || ! self::movesForward($notification->status, $report->status)) {
                    continue;
                }

                $notification->update([
                    'status' => $report->status,
                    'error' => $report->status === NotificationStatus::Failed ? $report->error : $notification->error,
                ]);
                $updated++;
            }

            return $updated;
        });
    }

    public static function movesForward(NotificationStatus $current, NotificationStatus $reported): bool
    {
        if ($current === NotificationStatus::Failed || $current === $reported) {
            return false;
        }

        return self::PROGRESS[$reported->value] > self::PROGRESS[$current->value];
    }
}
