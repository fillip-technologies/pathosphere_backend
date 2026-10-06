<?php

namespace App\Modules\Locker\Services;

use App\Modules\Booking\Enums\AbdmRequestStatus;
use App\Modules\Booking\Services\AbdmRequestLog;
use App\Modules\Locker\Contracts\Abdm\ConsentArtefact;
use App\Modules\Locker\Contracts\Abdm\ConsentNotified;
use App\Modules\Locker\Contracts\Abdm\HipError;
use App\Modules\Locker\Contracts\AbdmHipGateway;
use App\Modules\Locker\Enums\CareContextLinkStatus;
use App\Modules\Locker\Enums\ConsentPurpose;
use App\Modules\Locker\Enums\ConsentStatus;
use App\Modules\Locker\Errors\AbdmHipError;
use App\Modules\Locker\Models\AbdmCareContext;
use App\Modules\Locker\Models\Consent;
use App\Modules\Locker\StateMachines\ConsentStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mirrors ABDM consent artefacts about our records into `consents` (spec
 * §7.11: "mirrors ABDM consent artefacts"; §10: share only within a granted
 * artefact, honour revocation immediately).
 *
 * A granted artefact becomes a granted consent of the patient whose linked
 * care contexts it names; revocation and expiry end it at once, and every
 * data request checks it again before anything is sent. The artefact is
 * always acknowledged, even when it names nothing we hold: ABDM retries an
 * unacknowledged notification.
 */
final class AbdmConsentMirror
{
    public function __construct(
        private readonly AbdmHipGateway $gateway,
        private readonly AbdmRequestLog $requestLog,
        private readonly ConsentStateMachine $states,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function notified(ConsentNotified $notification): ?HipError
    {
        $ended = match ($notification->status) {
            ConsentNotified::REVOKED => ConsentStatus::Revoked,
            ConsentNotified::EXPIRED => ConsentStatus::Expired,
            ConsentNotified::DENIED => ConsentStatus::Denied,
            default => null,
        };
        $error = null;

        if ($notification->status === ConsentNotified::GRANTED && $notification->artefact !== null) {
            $error = $this->grant($notification->consentArtefactId, $notification->artefact);
        } elseif ($ended !== null) {
            $this->end($notification->consentArtefactId, $ended);
        } else {
            $error = new HipError('CONSENT_STATUS_UNKNOWN', 'Unknown consent status.');
        }

        $requestId = (string) Str::uuid();
        $this->requestLog->outbound('consent_on_notify', $requestId, AbdmRequestStatus::Pending, payloadMasked: [
            'consent_artefact_id' => $notification->consentArtefactId,
            'status' => $notification->status,
        ]);

        try {
            $this->gateway->acknowledgeConsent($requestId, $notification);
            $this->requestLog->settle($requestId, AbdmRequestStatus::Success);
        } catch (AbdmHipError $failure) {
            // Unacknowledged, ABDM sends the notification again; the mirror is already right.
            $this->requestLog->settle($requestId, AbdmRequestStatus::Failed, $failure->errorCode);
        }

        return $error;
    }

    private function grant(string $artefactId, ConsentArtefact $artefact): ?HipError
    {
        if (Consent::query()->where('consent_artefact_id', $artefactId)->exists()) {
            return null;
        }

        $careContexts = AbdmCareContext::query()
            ->whereIn('care_context_reference', $artefact->careContextReferences)
            ->where('link_status', CareContextLinkStatus::Linked)
            ->get();
        $patientIds = $careContexts->pluck('patient_id')->unique()->values();

        if ($patientIds->isEmpty()) {
            return new HipError('CARE_CONTEXT_NOT_FOUND', 'The consent names no record linked here.');
        }

        if ($patientIds->count() > 1) {
            // Never expected from ABDM; refusing is safer than guessing whose consent it is.
            return new HipError('CARE_CONTEXTS_OF_SEVERAL_PATIENTS', 'The consent names records of more than one patient.');
        }

        DB::transaction(function () use ($artefactId, $artefact, $careContexts, $patientIds): void {
            $consent = new Consent([
                'requester' => mb_substr($artefact->requesterName, 0, 150),
                'purpose' => self::purpose($artefact->purposeCode),
                'scope' => [
                    'source' => 'abdm',
                    'care_context_references' => $careContexts->pluck('care_context_reference')->values()->all(),
                    'hi_types' => $artefact->hiTypes,
                    'date_from' => $artefact->dateFrom->utc()->toIso8601ZuluString(),
                    'date_to' => $artefact->dateTo->utc()->toIso8601ZuluString(),
                    'access_mode' => $artefact->accessMode,
                    'purpose_code' => $artefact->purposeCode,
                ],
                'status' => ConsentStatus::Granted,
                'granted_at' => CarbonImmutable::now(),
                'expires_at' => $artefact->dataEraseAt,
            ]);
            $consent->patient_id = (string) $patientIds->first();
            $consent->consent_artefact_id = $artefactId;
            $consent->save();
            $this->auditLogger->recordCreated('consent.granted', $consent);
        });

        return null;
    }

    private function end(string $artefactId, ConsentStatus $to): void
    {
        $consent = Consent::query()->where('consent_artefact_id', $artefactId)->first();

        if ($consent === null || $consent->status === $to || ! $this->states->canTransition($consent->status, $to)) {
            return;
        }

        $this->states->transition($consent, $to, $to === ConsentStatus::Revoked ? ['revoked_at' => CarbonImmutable::now()] : []);
    }

    /** ABDM purpose codes in our categories; the code itself is kept in the scope. */
    private static function purpose(string $code): ConsentPurpose
    {
        return match (strtoupper($code)) {
            'CAREMGT', 'BTG' => ConsentPurpose::Treatment,
            'HPAYMT' => ConsentPurpose::Insurance,
            'PATRQT' => ConsentPurpose::Personal,
            default => ConsentPurpose::Other,
        };
    }
}
