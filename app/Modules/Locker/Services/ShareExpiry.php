<?php

namespace App\Modules\Locker\Services;

use App\Modules\Booking\Services\PeopleDirectory;
use App\Modules\Locker\Enums\ConsentStatus;
use App\Modules\Locker\Enums\ShareStatus;
use App\Modules\Locker\Models\Consent;
use App\Modules\Locker\Models\RecordShare;
use App\Modules\Locker\StateMachines\ConsentStateMachine;
use App\Modules\Locker\StateMachines\RecordShareStateMachine;
use App\Modules\Shared\Jobs\WithSystemScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Marks consents and shares past their end as expired. Access never waits
 * for this: an expired share is refused the moment it ends. This keeps the
 * stored status truthful for the patient's list and for audit.
 */
final class ShareExpiry
{
    public function __construct(
        private readonly ConsentStateMachine $consentStates,
        private readonly RecordShareStateMachine $shareStates,
        private readonly PeopleDirectory $people,
    ) {}

    public function expireEnded(CarbonImmutable $now): void
    {
        Consent::query()
            ->where('status', ConsentStatus::Granted)
            ->where('expires_at', '<=', $now)
            ->with('shares')
            // By ID, not offset: each row leaves the filter as it is expired.
            ->lazyById(200)
            ->each(function (Consent $consent): void {
                $this->asPatientsOrganization($consent->patient_id, fn () => DB::transaction(function () use ($consent): void {
                    $this->consentStates->transition($consent, ConsentStatus::Expired);
                    $consent->shares
                        ->where('status', ShareStatus::Active)
                        ->each(fn (RecordShare $share) => $this->shareStates->transition($share, ShareStatus::Expired));
                }));
            });

        RecordShare::query()
            ->where('status', ShareStatus::Active)
            ->where('expires_at', '<=', $now)
            ->with('record')
            ->lazyById(200)
            ->each(fn (RecordShare $share) => $this->asPatientsOrganization(
                $share->record->patient_id,
                fn () => $this->shareStates->transition($share, ShareStatus::Expired),
            ));
    }

    private function asPatientsOrganization(string $patientId, callable $callback): void
    {
        $organizationId = $this->people->patient($patientId)?->organizationId;

        if ($organizationId !== null) {
            WithSystemScope::run($organizationId, $callback);
        }
    }
}
