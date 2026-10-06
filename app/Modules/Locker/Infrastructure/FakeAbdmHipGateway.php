<?php

namespace App\Modules\Locker\Infrastructure;

use App\Modules\Locker\Contracts\Abdm\AbhaPatient;
use App\Modules\Locker\Contracts\Abdm\CareContext;
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
use App\Modules\Locker\Contracts\AbdmHipGateway;
use App\Modules\Locker\Errors\AbdmHipError;
use Carbon\CarbonImmutable;

/**
 * Local and test stand-in for the ABDM gateway until sandbox onboarding. It
 * writes every call exactly as the real adapter will (same messages, same
 * headers) and keeps it in `$sent` instead of sending it, so tests can play
 * the gateway: read what we sent, then post its callback.
 *
 * Callbacks are signed with HMAC-SHA256 of the body in `X-ABDM-Signature`,
 * like the M1 fake client (config services.abdm.callback_secret).
 */
final class FakeAbdmHipGateway implements AbdmHipGateway
{
    /** @var list<array{api: string, url: string, headers: array<string, string>, body: array<string, mixed>}> */
    public array $sent = [];

    /** Set to make the next calls fail as if ABDM were down. */
    public bool $unavailable = false;

    /** Set to make pushes to health information users fail. */
    public bool $pushFails = false;

    public function __construct(
        private readonly string $callbackSecret,
        private readonly string $cmId,
    ) {}

    public function readCallback(string $type, array $headers, string $rawBody): HipCallback
    {
        $signature = $headers['x-abdm-signature'] ?? '';

        if ($this->callbackSecret === '' || ! hash_equals(hash_hmac('sha256', $rawBody, $this->callbackSecret), $signature)) {
            throw AbdmHipError::invalidSignature();
        }

        return AbdmHipMessages::readCallback($type, $headers, $rawBody);
    }

    public function requestLinkToken(string $requestId, string $hipId, AbhaPatient $patient): void
    {
        $this->send('generate_link_token', $requestId, $hipId, AbdmHipMessages::linkTokenRequest($patient));
    }

    public function linkCareContexts(string $requestId, string $hipId, string $linkToken, AbhaPatient $patient, PatientCareContexts $contexts): void
    {
        $this->send('link_care_contexts', $requestId, $hipId, AbdmHipMessages::linkCareContexts($patient, $contexts), ['X-LINK-TOKEN' => $linkToken]);
    }

    public function notifyCareContext(string $requestId, string $hipId, AbhaPatient $patient, string $patientReference, CareContext $context, CarbonImmutable $recordDate): void
    {
        $this->send('notify_care_context', $requestId, $hipId, AbdmHipMessages::notifyCareContext($hipId, $patient, $patientReference, $context, $recordDate));
    }

    public function answerDiscovery(string $requestId, DiscoveryRequested $request, ?PatientCareContexts $found, array $matchedBy, ?HipError $error): void
    {
        $this->send('on_discover', $requestId, $request->hipId, AbdmHipMessages::onDiscover($request, $found, $matchedBy, $error));
    }

    public function answerLinkRequest(string $requestId, LinkRequested $request, ?LinkChallenge $challenge, ?HipError $error): void
    {
        $this->send('on_link_init', $requestId, $request->hipId, AbdmHipMessages::onLinkInit($request, $challenge, $error));
    }

    public function answerLinkConfirmation(string $requestId, LinkConfirmed $request, ?PatientCareContexts $linked, ?HipError $error): void
    {
        $this->send('on_link_confirm', $requestId, $request->hipId, AbdmHipMessages::onLinkConfirm($request, $linked, $error));
    }

    public function acknowledgeConsent(string $requestId, ConsentNotified $notification): void
    {
        $this->send('consent_on_notify', $requestId, $notification->hipId, AbdmHipMessages::consentOnNotify($notification));
    }

    public function answerHealthInformationRequest(string $requestId, HealthInformationRequested $request, ?HipError $error): void
    {
        $this->send('health_information_on_request', $requestId, $request->hipId, AbdmHipMessages::healthInformationOnRequest($request, $error));
    }

    public function pushHealthData(string $dataPushUrl, HealthDataPage $page): void
    {
        if ($this->pushFails) {
            throw AbdmHipError::unavailable();
        }

        $this->sent[] = ['api' => 'data_push', 'url' => $dataPushUrl, 'headers' => [], 'body' => AbdmHipMessages::dataPush($page)];
    }

    public function notifyTransfer(string $requestId, string $hipId, string $consentArtefactId, string $transactionId, bool $transferred, array $deliveries): void
    {
        $this->send('health_information_notify', $requestId, $hipId, AbdmHipMessages::healthInformationNotify(
            $hipId,
            $consentArtefactId,
            $transactionId,
            $transferred,
            $deliveries,
            CarbonImmutable::now(),
        ));
    }

    /** @return list<array{api: string, url: string, headers: array<string, string>, body: array<string, mixed>}> */
    public function sentTo(string $api): array
    {
        return array_values(array_filter($this->sent, fn (array $call) => $call['api'] === $api));
    }

    /** @return array{api: string, url: string, headers: array<string, string>, body: array<string, mixed>}|null */
    public function lastSentTo(string $api): ?array
    {
        $calls = $this->sentTo($api);

        return $calls === [] ? null : $calls[array_key_last($calls)];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $extraHeaders
     */
    private function send(string $api, string $requestId, string $hipId, array $body, array $extraHeaders = []): void
    {
        if ($this->unavailable) {
            throw AbdmHipError::unavailable();
        }

        $this->sent[] = [
            'api' => $api,
            'url' => AbdmHipMessages::PATHS[$api],
            'headers' => AbdmHipMessages::headers($requestId, $hipId, CarbonImmutable::now(), $this->cmId) + $extraHeaders,
            'body' => $body,
        ];
    }
}
