<?php

namespace App\Modules\Network\Jobs;

use App\Modules\Network\Enums\AgreementStatus;
use App\Modules\Network\Enums\FranchiseDocumentStatus;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\FranchiseAgreement;
use App\Modules\Network\Models\FranchiseDocument;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Jobs\WithSystemScope;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Expiry alerts (spec §9, daily) for the network's papers: agreements and
 * KYC documents (told to the franchise) and NABL certificates (told to the
 * lab). Each is alerted on the day it is exactly 60 and 30 days from expiry,
 * so a daily run never repeats an alert.
 */
final class AlertExpiringNetworkPapers implements ShouldQueue
{
    use Queueable;

    private const DAYS_BEFORE = [60, 30];

    public function handle(NetworkDirectory $network, NotificationService $notifications): void
    {
        $today = CarbonImmutable::parse(CarbonImmutable::now('Asia/Kolkata')->toDateString());
        $dates = array_map(fn (int $days) => $today->addDays($days)->toDateString(), self::DAYS_BEFORE);

        foreach ($network->organizationIds() as $organizationId) {
            WithSystemScope::run($organizationId, function () use ($dates, $today, $notifications): void {
                FranchiseAgreement::query()->with('franchise')->where('status', AgreementStatus::Active)->whereIn('end_date', $dates)->get()
                    ->each(fn (FranchiseAgreement $agreement) => $notifications->notify('partner_paper_expiring', $this->franchiseRecipient($agreement->franchise), [
                        'partner_name' => $agreement->franchise->name,
                        'paper' => "agreement {$agreement->agreement_no}",
                        'expires_on' => $agreement->end_date->format('d M Y'),
                        'days_left' => (string) (int) $today->diffInDays($agreement->end_date),
                    ]));

                FranchiseDocument::query()->where('status', FranchiseDocumentStatus::Verified)->whereIn('expires_on', $dates)->get()
                    ->each(function (FranchiseDocument $document) use ($today, $notifications): void {
                        $franchise = Franchise::query()->findOrFail($document->franchise_id);
                        $notifications->notify('partner_paper_expiring', $this->franchiseRecipient($franchise), [
                            'partner_name' => $franchise->name,
                            'paper' => str_replace('_', ' ', $document->doc_type->value),
                            'expires_on' => $document->expires_on?->format('d M Y') ?? '',
                            'days_left' => (string) (int) $today->diffInDays($document->expires_on),
                        ]);
                    });

                Branch::query()->whereIn('nabl_valid_till', $dates)->get()
                    ->each(fn (Branch $lab) => $notifications->notify('nabl_expiring', new NotificationRecipient('branch', $lab->id, $lab->organization_id, $lab->phone, null, false), [
                        'branch_name' => $lab->name,
                        'certificate_no' => (string) $lab->nabl_certificate_no,
                        'expires_on' => $lab->nabl_valid_till?->format('d M Y') ?? '',
                    ]));
            });
        }
    }

    private function franchiseRecipient(Franchise $franchise): NotificationRecipient
    {
        return new NotificationRecipient('franchise', $franchise->id, $franchise->organization_id, $franchise->phone, $franchise->email, false);
    }
}
