<?php

namespace App\Modules\Locker\Contracts;

use App\Modules\Locker\Contracts\Abdm\AbhaPatient;
use App\Modules\Locker\Contracts\Abdm\CareContext;
use App\Modules\Locker\Contracts\Abdm\CareContextDelivery;
use App\Modules\Locker\Contracts\Abdm\ConsentNotified;
use App\Modules\Locker\Contracts\Abdm\DiscoveryRequested;
use App\Modules\Locker\Contracts\Abdm\HealthDataPage;
use App\Modules\Locker\Contracts\Abdm\HealthInformationRequested;
use App\Modules\Locker\Contracts\Abdm\HipCallback;
use App\Modules\Locker\Contracts\Abdm\HipError;
use App\Modules\Locker\Contracts\Abdm\LinkChallenge;
use App\Modules\Locker\Contracts\Abdm\LinkConfirmed;
use App\Modules\Locker\Contracts\Abdm\LinkRequested;
use App\Modules\Locker\Contracts\Abdm\PatientCareContexts;
use App\Modules\Locker\Errors\AbdmHipError;
use Carbon\CarbonImmutable;

/**
 * ABDM gateway boundary for milestone M2: our labs as Health Information
 * Providers (spec §5.7). Each lab is a provider under its HFR ID.
 *
 * ABDM is asynchronous: every call here is answered later by a callback that
 * carries our request ID, so callers log the request before calling. Our
 * answers to ABDM's own calls (discovery, linking, consent, data requests)
 * are separate calls too, made with the ID of the call they answer.
 *
 * Failures to reach ABDM throw AbdmHipError::unavailable().
 */
interface AbdmHipGateway
{
    /**
     * Checks the gateway's signature and reads one callback.
     *
     * @param  array<string, string>  $headers  lower-case names
     *
     * @throws AbdmHipError when unsigned or malformed
     */
    public function readCallback(string $type, array $headers, string $rawBody): HipCallback;

    /** Asks for the token that lets this facility link records to the patient's ABHA. */
    public function requestLinkToken(string $requestId, string $hipId, AbhaPatient $patient): void;

    public function linkCareContexts(string $requestId, string $hipId, string $linkToken, AbhaPatient $patient, PatientCareContexts $contexts): void;

    /** Tells the patient's ABHA apps that a newly linked record exists. */
    public function notifyCareContext(string $requestId, string $hipId, AbhaPatient $patient, string $patientReference, CareContext $context, CarbonImmutable $recordDate): void;

    /** @param  list<string>  $matchedBy  abha_number, mobile, uhid */
    public function answerDiscovery(string $requestId, DiscoveryRequested $request, ?PatientCareContexts $found, array $matchedBy, ?HipError $error): void;

    public function answerLinkRequest(string $requestId, LinkRequested $request, ?LinkChallenge $challenge, ?HipError $error): void;

    public function answerLinkConfirmation(string $requestId, LinkConfirmed $request, ?PatientCareContexts $linked, ?HipError $error): void;

    public function acknowledgeConsent(string $requestId, ConsentNotified $notification): void;

    public function answerHealthInformationRequest(string $requestId, HealthInformationRequested $request, ?HipError $error): void;

    /** Sends encrypted records straight to the health information user's push URL. */
    public function pushHealthData(string $dataPushUrl, HealthDataPage $page): void;

    /** @param  list<CareContextDelivery>  $deliveries */
    public function notifyTransfer(string $requestId, string $hipId, string $consentArtefactId, string $transactionId, bool $transferred, array $deliveries): void;
}
