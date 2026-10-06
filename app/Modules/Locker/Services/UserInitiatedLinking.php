<?php

namespace App\Modules\Locker\Services;

use App\Modules\Auth\Enums\OtpPurpose;
use App\Modules\Auth\Services\OtpChallenges;
use App\Modules\Booking\Enums\AbdmRequestStatus;
use App\Modules\Booking\Services\AbdmRequestLog;
use App\Modules\Booking\Services\PeopleDirectory;
use App\Modules\Lab\Services\ReleasedReports;
use App\Modules\Locker\Contracts\Abdm\CareContext;
use App\Modules\Locker\Contracts\Abdm\DiscoveryRequested;
use App\Modules\Locker\Contracts\Abdm\HipError;
use App\Modules\Locker\Contracts\Abdm\LinkChallenge;
use App\Modules\Locker\Contracts\Abdm\LinkConfirmed;
use App\Modules\Locker\Contracts\Abdm\LinkRequested;
use App\Modules\Locker\Contracts\Abdm\PatientCareContexts;
use App\Modules\Locker\Contracts\AbdmHipGateway;
use App\Modules\Locker\Domain\DiscoveryMatcher;
use App\Modules\Locker\Enums\CareContextLinkStatus;
use App\Modules\Locker\Errors\AbdmHipError;
use App\Modules\Locker\Models\AbdmCareContext;
use App\Modules\Locker\StateMachines\CareContextStateMachine;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Errors\DomainError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A patient, from their ABHA app, finds and links their reports at one of
 * our labs (ABDM user-initiated linking, spec §5.7 M2):
 *
 *   discover → we match them (DiscoveryMatcher) and list their unlinked
 *              reports at that lab;
 *   init     → they pick reports; we send a code to the phone on our record;
 *   confirm  → they enter it in their app; we link the reports.
 *
 * Between steps the session lives encrypted in the cache, keyed by ABDM's
 * transaction and by our link reference.
 */
final class UserInitiatedLinking
{
    public function __construct(
        private readonly AbdmHipGateway $gateway,
        private readonly AbdmRequestLog $requestLog,
        private readonly PeopleDirectory $people,
        private readonly ReleasedReports $releasedReports,
        private readonly NetworkDirectory $network,
        private readonly CareContexts $careContexts,
        private readonly CareContextStateMachine $states,
        private readonly OtpChallenges $otps,
    ) {}

    public function discover(DiscoveryRequested $request): ?HipError
    {
        $labBranchId = $this->network->branchIdByHfrId($request->hipId);

        if ($labBranchId === null) {
            return $this->answerDiscovery($request, null, [], new HipError('UNKNOWN_FACILITY', 'This facility is not registered with us.'));
        }

        $match = DiscoveryMatcher::match($request, $this->people->abhaDiscoveryCandidates($request->verifiedAbhaNumber, $request->verifiedMobile));

        if ($match->patient === null) {
            return $this->answerDiscovery($request, null, [], new HipError(
                (string) $match->errorCode,
                $match->errorCode === $match::AMBIGUOUS
                    ? 'More than one patient matches these details. Add your UHID from a report to narrow the search.'
                    : 'No patient matches these details at this facility.',
            ));
        }

        $patient = $match->patient;
        $linked = AbdmCareContext::query()->where('patient_id', $patient->patientId)->where('link_status', CareContextLinkStatus::Linked)->pluck('report_id')->all();
        $reports = array_values(array_filter(
            $this->releasedReports->currentAtLab($this->people->idsMergedInto($patient->patientId), $labBranchId),
            fn ($report) => ! in_array($report->reportId, $linked, true),
        ));

        if ($reports === []) {
            return $this->answerDiscovery($request, null, [], new HipError('CARE_CONTEXTS_NOT_FOUND', 'There are no new reports to link at this facility.'));
        }

        $offered = array_map(fn ($report) => new CareContext($report->reportId, CareContexts::displayName($report->orderDate, $report->labName, $report->version)), $reports);
        $this->remember($this->discoveryKey($request->transactionId), [
            'patient_id' => $patient->patientId,
            'organization_id' => $patient->organizationId,
            'uhid' => $patient->uhid,
            'lab_branch_id' => $labBranchId,
            'report_ids' => array_map(fn (CareContext $context) => $context->reference, $offered),
        ]);

        return $this->answerDiscovery($request, new PatientCareContexts($patient->uhid, $patient->name, $offered), $match->matchedBy, null);
    }

    public function requestLink(LinkRequested $request): ?HipError
    {
        $session = $this->recall($this->discoveryKey($request->transactionId));
        $error = match (true) {
            $session === null => new HipError('LINK_SESSION_EXPIRED', 'Search for your records again.'),
            strcasecmp($request->patientReference, $session['uhid']) !== 0,
            $request->careContextReferences === [],
            array_diff($request->careContextReferences, $session['report_ids']) !== [] => new HipError('CARE_CONTEXT_NOT_FOUND', 'These records were not offered to you.'),
            default => null,
        };

        if ($error !== null) {
            return $this->answerLinkRequest($request, null, $error);
        }

        $recipient = $this->people->patientRecipient($session['patient_id']);

        if ($recipient === null || $recipient->phone === null) {
            return $this->answerLinkRequest($request, null, new HipError('PATIENT_NOT_FOUND', 'No phone is on record to confirm the link.'));
        }

        try {
            $expiresAt = $this->otps->send($recipient->phone, OtpPurpose::CareContextLink, null, $this->network->organizationName($session['organization_id']));
        } catch (DomainError $error) {
            return $this->answerLinkRequest($request, null, new HipError($error->errorCode, $error->getMessage()));
        }

        $linkReference = Str::random(32);
        $this->remember($this->linkKey($linkReference), $session + [
            'phone' => $recipient->phone,
            'chosen_report_ids' => array_values(array_unique($request->careContextReferences)),
        ]);

        $hint = str_repeat('*', max(0, strlen($recipient->phone) - 4)).substr($recipient->phone, -4);

        return $this->answerLinkRequest($request, new LinkChallenge($linkReference, $hint, $expiresAt), null);
    }

    public function confirmLink(LinkConfirmed $request): ?HipError
    {
        $session = $this->recall($this->linkKey($request->linkReference));

        if ($session === null) {
            return $this->answerLinkConfirmation($request, null, new HipError('LINK_SESSION_EXPIRED', 'The link request has expired. Start again from your ABHA app.'));
        }

        try {
            $this->otps->verify($session['phone'], OtpPurpose::CareContextLink, $request->code);
        } catch (DomainError $error) {
            return $this->answerLinkConfirmation($request, null, new HipError($error->errorCode, $error->getMessage()));
        }

        Cache::forget($this->linkKey($request->linkReference));

        $linked = DB::transaction(function () use ($session): array {
            $linked = [];

            foreach ($session['chosen_report_ids'] as $reportId) {
                $careContext = $this->careContexts->ensure($session['organization_id'], $reportId, $session['patient_id']);

                if ($careContext === null) {
                    continue;
                }

                if ($careContext->link_status !== CareContextLinkStatus::Linked) {
                    $this->states->transition($careContext, CareContextLinkStatus::Linked, ['linked_at' => CarbonImmutable::now(), 'error_code' => null]);
                }

                $linked[] = CareContexts::asAbdmCareContext($careContext);
            }

            return $linked;
        });

        $patient = $this->people->patient($session['patient_id']);

        return $this->answerLinkConfirmation($request, new PatientCareContexts($session['uhid'], $patient->name ?? '', $linked), null);
    }

    /** @param  list<string>  $matchedBy */
    private function answerDiscovery(DiscoveryRequested $request, ?PatientCareContexts $found, array $matchedBy, ?HipError $error): ?HipError
    {
        $this->answer('on_discover', $found === null ? null : $found->reference, fn (string $requestId) => $this->gateway->answerDiscovery($requestId, $request, $found, $matchedBy, $error), $request->transactionId, $error);

        return $error;
    }

    private function answerLinkRequest(LinkRequested $request, ?LinkChallenge $challenge, ?HipError $error): ?HipError
    {
        $this->answer('on_link_init', null, fn (string $requestId) => $this->gateway->answerLinkRequest($requestId, $request, $challenge, $error), $request->transactionId, $error);

        return $error;
    }

    private function answerLinkConfirmation(LinkConfirmed $request, ?PatientCareContexts $linked, ?HipError $error): ?HipError
    {
        $this->answer('on_link_confirm', null, fn (string $requestId) => $this->gateway->answerLinkConfirmation($requestId, $request, $linked, $error), null, $error);

        return $error;
    }

    /** @param  callable(string): void  $send */
    private function answer(string $apiName, ?string $uhid, callable $send, ?string $transactionId, ?HipError $error): void
    {
        $requestId = (string) Str::uuid();
        $this->requestLog->outbound($apiName, $requestId, AbdmRequestStatus::Pending, txnId: $transactionId, errorCode: $error === null ? null : mb_substr($error->code, 0, 50), payloadMasked: $uhid === null ? null : ['patient_reference' => $uhid]);

        try {
            $send($requestId);
            $this->requestLog->settle($requestId, AbdmRequestStatus::Success, $error?->code);
        } catch (AbdmHipError $failure) {
            // ABDM times the step out on its side and the patient tries again from their app.
            $this->requestLog->settle($requestId, AbdmRequestStatus::Failed, $failure->errorCode);
        }
    }

    /** @param  array<string, mixed>  $session */
    private function remember(string $key, array $session): void
    {
        Cache::put($key, Crypt::encryptString((string) json_encode($session)), now()->addMinutes((int) config('pathology.abdm.link_session_minutes')));
    }

    /** @return array<string, mixed>|null */
    private function recall(string $key): ?array
    {
        $stored = Cache::get($key);
        $session = is_string($stored) ? json_decode(Crypt::decryptString($stored), true) : null;

        return is_array($session) ? $session : null;
    }

    private function discoveryKey(string $transactionId): string
    {
        return 'abdm_discovery:'.hash('sha256', $transactionId);
    }

    private function linkKey(string $linkReference): string
    {
        return 'abdm_link_session:'.hash('sha256', $linkReference);
    }
}
