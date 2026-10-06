<?php

namespace App\Modules\Locker\Jobs;

use App\Modules\Locker\Enums\CareContextLinkStatus;
use App\Modules\Locker\Models\AbdmCareContext;
use App\Modules\Locker\Services\CareContextLinker;
use App\Modules\Locker\StateMachines\CareContextStateMachine;
use App\Modules\Shared\Jobs\WithSystemScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tries again to link reports ABDM did not link: failed ones (up to
 * `abdm.link_max_attempts`) and pending ones whose request went unanswered.
 */
final class RetryCareContextLinks implements ShouldQueue
{
    use Queueable;

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithSystemScope(null)];
    }

    public function handle(CareContextLinker $linker, CareContextStateMachine $states): void
    {
        AbdmCareContext::query()
            ->where('link_status', CareContextLinkStatus::Failed)
            ->where('link_attempts', '<', (int) config('pathology.abdm.link_max_attempts'))
            ->lazyById(200)
            ->each(fn (AbdmCareContext $careContext) => $states->transition($careContext, CareContextLinkStatus::Pending, ['link_request_id' => null]));

        $staleBefore = CarbonImmutable::now()->subMinutes((int) config('pathology.abdm.link_retry_after_minutes'));

        AbdmCareContext::query()
            ->where('link_status', CareContextLinkStatus::Pending)
            ->where(fn ($query) => $query->whereNull('link_request_id')->orWhere('updated_at', '<', $staleBefore))
            ->distinct()
            ->pluck('patient_id')
            ->each(fn (string $patientId) => $linker->linkPending($patientId));
    }
}
