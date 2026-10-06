<?php

namespace App\Modules\Locker\Services;

use App\Modules\Booking\Enums\AbdmRequestStatus;
use App\Modules\Booking\Services\AbdmRequestLog;
use App\Modules\Booking\Services\AbhaIdentity;
use App\Modules\Booking\Services\PeopleDirectory;
use App\Modules\Lab\Services\ReleasedReports;
use App\Modules\Locker\Contracts\Abdm\CareContextsLinked;
use App\Modules\Locker\Contracts\Abdm\LinkTokenIssued;
use App\Modules\Locker\Contracts\Abdm\PatientCareContexts;
use App\Modules\Locker\Contracts\AbdmHipGateway;
use App\Modules\Locker\Enums\CareContextLinkStatus;
use App\Modules\Locker\Errors\AbdmHipError;
use App\Modules\Locker\Models\AbdmCareContext;
use App\Modules\Locker\StateMachines\CareContextStateMachine;
use App\Modules\Network\Services\NetworkDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Links each released report to the patient's ABHA without the patient
 * having to ask (spec §5.7 M2, §9 ReportReleased: "ABDM care-context link
 * if ABHA linked"):
 *
 *   1. ask ABDM for a link token for the patient at the lab's facility;
 *   2. when it arrives, link every pending report of the patient there;
 *   3. when ABDM confirms, mark them linked and tell the patient's ABHA apps.
 *
 * Each step is answered by a callback; a step with no answer is retried by
 * RetryCareContextLinks. Patients without an ABHA are skipped: they can
 * still find and link their reports from their ABHA app.
 */
final class CareContextLinker
{
    public function __construct(
        private readonly AbdmHipGateway $gateway,
        private readonly AbdmRequestLog $requestLog,
        private readonly PeopleDirectory $people,
        private readonly ReleasedReports $releasedReports,
        private readonly NetworkDirectory $network,
        private readonly CareContexts $careContexts,
        private readonly CareContextStateMachine $states,
    ) {}

    public function reportReleased(string $organizationId, string $reportId): void
    {
        if (! (bool) config('pathology.abdm.link_on_release')) {
            return;
        }

        $report = $this->releasedReports->forExchange($organizationId, $reportId);
        $identity = $report === null ? null : $this->people->abhaIdentity($report->patientId);

        if ($identity === null || $this->careContexts->ensure($organizationId, $reportId, $identity->patientId) === null) {
            return;
        }

        $this->linkPending($identity->patientId);
    }

    /** Sends the patient's pending care contexts to ABDM, per facility, asking for a link token first where needed. */
    public function linkPending(string $patientId): void
    {
        $identity = $this->people->abhaIdentity($patientId);

        if ($identity === null) {
            return;
        }

        $staleBefore = CarbonImmutable::now()->subMinutes((int) config('pathology.abdm.link_retry_after_minutes'));

        AbdmCareContext::query()
            ->where('patient_id', $identity->patientId)
            ->where('link_status', CareContextLinkStatus::Pending)
            ->where(fn ($query) => $query->whereNull('link_request_id')->orWhere('updated_at', '<', $staleBefore))
            ->orderBy('created_at')
            ->get()
            ->groupBy('hip_branch_id')
            ->each(function (Collection $careContexts, string $hipBranchId) use ($identity): void {
                $hipId = $this->network->letterhead($hipBranchId)->hfrId;

                if ($hipId !== null) {
                    $this->linkAtFacility($identity, $hipBranchId, $hipId, $careContexts);
                }
            });
    }

    /** Step 2: the token arrived (or was refused). */
    public function linkTokenIssued(LinkTokenIssued $answer): void
    {
        $request = $this->requestLog->outboundRequest($answer->respondingToRequestId);

        if ($request === null || $request->patientId === null || $request->branchId === null) {
            return;
        }

        Cache::forget($this->tokenRequestedKey($request->patientId, $request->branchId));

        if ($answer->error !== null || $answer->linkToken === null) {
            $errorCode = $answer->error === null ? 'LINK_TOKEN_MISSING' : $answer->error->code;
            $this->requestLog->settle($request->requestId, AbdmRequestStatus::Failed, $errorCode);
            $this->failPending($request->patientId, $request->branchId, $errorCode);

            return;
        }

        $this->requestLog->settle($request->requestId, AbdmRequestStatus::Success);
        Cache::put(
            $this->tokenKey($request->patientId, $request->branchId),
            Crypt::encryptString($answer->linkToken),
            now()->addMinutes((int) config('pathology.abdm.link_token_ttl_minutes')),
        );

        $this->linkPending($request->patientId);
    }

    /** Step 3: ABDM linked the care contexts (or refused). */
    public function careContextsLinked(CareContextsLinked $answer): void
    {
        $careContexts = AbdmCareContext::query()
            ->where('link_request_id', $answer->respondingToRequestId)
            ->where('link_status', CareContextLinkStatus::Pending)
            ->get();

        if ($answer->error !== null) {
            $errorCode = $answer->error->code;
            $this->requestLog->settle($answer->respondingToRequestId, AbdmRequestStatus::Failed, $errorCode);
            $careContexts->each(fn (AbdmCareContext $careContext) => $this->states->transition($careContext, CareContextLinkStatus::Failed, [
                'error_code' => mb_substr($errorCode, 0, 50),
            ]));

            // The token may have expired; the retry asks for a new one.
            $careContexts->unique(fn (AbdmCareContext $careContext) => $careContext->patient_id.'|'.$careContext->hip_branch_id)
                ->each(fn (AbdmCareContext $careContext) => Cache::forget($this->tokenKey($careContext->patient_id, $careContext->hip_branch_id)));

            return;
        }

        $this->requestLog->settle($answer->respondingToRequestId, AbdmRequestStatus::Success);

        foreach ($careContexts as $careContext) {
            $this->states->transition($careContext, CareContextLinkStatus::Linked, ['linked_at' => CarbonImmutable::now(), 'error_code' => null]);
            $this->notifyPatientApps($careContext);
        }
    }

    /** @param  Collection<int, AbdmCareContext>  $careContexts */
    private function linkAtFacility(AbhaIdentity $identity, string $hipBranchId, string $hipId, Collection $careContexts): void
    {
        $token = $this->linkToken($identity->patientId, $hipBranchId);

        if ($token === null) {
            $this->requestLinkToken($identity, $hipBranchId, $hipId);

            return;
        }

        $requestId = (string) Str::uuid();
        $this->requestLog->outbound('link_care_contexts', $requestId, AbdmRequestStatus::Pending, $identity->patientId, $hipBranchId, payloadMasked: [
            'care_context_references' => $careContexts->pluck('care_context_reference')->all(),
        ]);

        foreach ($careContexts as $careContext) {
            $careContext->forceFill(['link_request_id' => $requestId, 'link_attempts' => $careContext->link_attempts + 1])->save();
        }

        try {
            $this->gateway->linkCareContexts($requestId, $hipId, $token, CareContexts::abhaPatient($identity), new PatientCareContexts(
                $identity->uhid,
                $identity->name,
                $careContexts->map(fn (AbdmCareContext $careContext) => CareContexts::asAbdmCareContext($careContext))->values()->all(),
            ));
        } catch (AbdmHipError $error) {
            // Stays pending with a request on record: the retry job tries again once it is stale.
            $this->requestLog->settle($requestId, AbdmRequestStatus::Failed, $error->errorCode);
        }
    }

    private function requestLinkToken(AbhaIdentity $identity, string $hipBranchId, string $hipId): void
    {
        $inFlight = $this->tokenRequestedKey($identity->patientId, $hipBranchId);

        // One request at a time; the marker lapses so an unanswered request is asked again.
        if (! Cache::add($inFlight, true, now()->addMinutes((int) config('pathology.abdm.link_retry_after_minutes')))) {
            return;
        }

        $requestId = (string) Str::uuid();
        $this->requestLog->outbound('generate_link_token', $requestId, AbdmRequestStatus::Pending, $identity->patientId, $hipBranchId);

        try {
            $this->gateway->requestLinkToken($requestId, $hipId, CareContexts::abhaPatient($identity));
        } catch (AbdmHipError $error) {
            $this->requestLog->settle($requestId, AbdmRequestStatus::Failed, $error->errorCode);
            Cache::forget($inFlight);
        }
    }

    private function notifyPatientApps(AbdmCareContext $careContext): void
    {
        $identity = $this->people->abhaIdentity($careContext->patient_id);
        $hipId = $this->network->letterhead($careContext->hip_branch_id)->hfrId;

        if ($identity === null || $hipId === null) {
            return;
        }

        $requestId = (string) Str::uuid();
        $this->requestLog->outbound('notify_care_context', $requestId, AbdmRequestStatus::Pending, $identity->patientId, $careContext->hip_branch_id, payloadMasked: [
            'care_context_reference' => $careContext->care_context_reference,
        ]);

        try {
            $this->gateway->notifyCareContext($requestId, $hipId, CareContexts::abhaPatient($identity), $identity->uhid, CareContexts::asAbdmCareContext($careContext), $careContext->record->record_date);
            $this->requestLog->settle($requestId, AbdmRequestStatus::Success);
        } catch (AbdmHipError $error) {
            // The link stands; the patient's app simply learns of it later.
            $this->requestLog->settle($requestId, AbdmRequestStatus::Failed, $error->errorCode);
        }
    }

    private function failPending(string $patientId, string $hipBranchId, string $errorCode): void
    {
        AbdmCareContext::query()
            ->where('patient_id', $patientId)
            ->where('hip_branch_id', $hipBranchId)
            ->where('link_status', CareContextLinkStatus::Pending)
            ->get()
            ->each(fn (AbdmCareContext $careContext) => $this->states->transition($careContext, CareContextLinkStatus::Failed, [
                'error_code' => mb_substr($errorCode, 0, 50),
                'link_attempts' => $careContext->link_attempts + 1,
            ]));
    }

    private function linkToken(string $patientId, string $hipBranchId): ?string
    {
        $stored = Cache::get($this->tokenKey($patientId, $hipBranchId));

        return is_string($stored) ? Crypt::decryptString($stored) : null;
    }

    private function tokenKey(string $patientId, string $hipBranchId): string
    {
        return "abdm_link_token:{$patientId}:{$hipBranchId}";
    }

    private function tokenRequestedKey(string $patientId, string $hipBranchId): string
    {
        return "abdm_link_token_requested:{$patientId}:{$hipBranchId}";
    }
}
