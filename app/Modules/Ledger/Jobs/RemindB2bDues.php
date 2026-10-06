<?php

namespace App\Modules\Ledger\Jobs;

use App\Modules\Booking\Services\PartnerBillingFacts;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Network\Services\PartnerAccounts;
use App\Modules\Shared\Jobs\WithSystemScope;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** B2B dues reminder (spec §9, daily 10:00): clients with invoices past their credit days. */
final class RemindB2bDues implements ShouldQueue
{
    use Queueable;

    public function handle(NetworkDirectory $network, PartnerBillingFacts $billing, PartnerAccounts $accounts, NotificationService $notifications): void
    {
        $today = CarbonImmutable::parse(CarbonImmutable::now('Asia/Kolkata')->toDateString());

        foreach ($network->organizationIds() as $organizationId) {
            WithSystemScope::run($organizationId, function () use ($organizationId, $today, $billing, $accounts, $notifications): void {
                foreach ($billing->overdueB2bInvoices($organizationId, $today) as $dues) {
                    $client = $accounts->find($organizationId, PartnerType::B2bClient, $dues->b2bClientId);

                    if ($client === null) {
                        continue;
                    }

                    $notifications->notify(
                        'dues_reminder',
                        new NotificationRecipient($client->type->value, $client->id, $organizationId, $client->phone, $client->email, false),
                        [
                            'partner_name' => $client->name,
                            'invoice_count' => (string) $dues->invoiceCount,
                            'amount' => (string) $dues->amountDue,
                            'oldest_due_date' => $dues->oldestDueDate->format('d M Y'),
                        ],
                    );
                }
            });
        }
    }
}
