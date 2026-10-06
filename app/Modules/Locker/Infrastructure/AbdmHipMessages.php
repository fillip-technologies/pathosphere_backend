<?php

namespace App\Modules\Locker\Infrastructure;

use App\Modules\Locker\Contracts\Abdm\AbhaPatient;
use App\Modules\Locker\Contracts\Abdm\CareContext;
use App\Modules\Locker\Contracts\Abdm\CareContextDelivery;
use App\Modules\Locker\Contracts\Abdm\CareContextsLinked;
use App\Modules\Locker\Contracts\Abdm\ConsentArtefact;
use App\Modules\Locker\Contracts\Abdm\ConsentNotified;
use App\Modules\Locker\Contracts\Abdm\DiscoveryRequested;
use App\Modules\Locker\Contracts\Abdm\HealthDataPage;
use App\Modules\Locker\Contracts\Abdm\HealthInformationRequested;
use App\Modules\Locker\Contracts\Abdm\HipCallback;
use App\Modules\Locker\Contracts\Abdm\HipError;
use App\Modules\Locker\Contracts\Abdm\KeyMaterial;
use App\Modules\Locker\Contracts\Abdm\LinkChallenge;
use App\Modules\Locker\Contracts\Abdm\LinkConfirmed;
use App\Modules\Locker\Contracts\Abdm\LinkRequested;
use App\Modules\Locker\Contracts\Abdm\LinkTokenIssued;
use App\Modules\Locker\Contracts\Abdm\PatientCareContexts;
use App\Modules\Locker\Errors\AbdmHipError;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * ABDM's HIP message formats (v3 gateway APIs), in both directions. The only
 * place ABDM field names appear: callbacks are read into our DTOs, and our
 * calls are written from them.
 *
 * Written from ABDM's published v3 documentation before sandbox access.
 * Paths, error codes and field spellings must be confirmed against recorded
 * sandbox traffic during onboarding (BUILD_PLAN Phase 8); the contract tests
 * pin today's understanding so any change is a visible diff.
 */
final class AbdmHipMessages
{
    /** Our callback route segment => what ABDM sends there. */
    public const CALLBACK_TYPES = [
        'link-token' => 'on-generate-token',
        'care-contexts-linked' => 'on_carecontext',
        'discover' => 'care-context/discover',
        'link-init' => 'link/care-context/init',
        'link-confirm' => 'link/care-context/confirm',
        'consent-notify' => 'consent/request/hip/notify',
        'health-information-request' => 'health-information/request',
    ];

    /** Gateway paths of our calls, relative to the gateway base URL. */
    public const PATHS = [
        'generate_link_token' => '/api/hiecm/v3/token/generate-token',
        'link_care_contexts' => '/api/hiecm/hip/v3/link/carecontext',
        'notify_care_context' => '/api/hiecm/hip/v3/link/context/notify',
        'on_discover' => '/api/hiecm/user-initiated-linking/v3/patient/care-context/on-discover',
        'on_link_init' => '/api/hiecm/user-initiated-linking/v3/link/care-context/on-init',
        'on_link_confirm' => '/api/hiecm/user-initiated-linking/v3/link/care-context/on-confirm',
        'consent_on_notify' => '/api/hiecm/consent/v3/request/hip/on-notify',
        'health_information_on_request' => '/api/hiecm/data-flow/v3/health-information/hip/on-request',
        'health_information_notify' => '/api/hiecm/data-flow/v3/health-information/notify',
    ];

    public const HI_TYPE_DIAGNOSTIC_REPORT = 'DiagnosticReport';

    private const MEDIA_FHIR = 'application/fhir+json';

    private const KEY_PARAMETERS = 'Curve25519/32byte random key';

    /**
     * @param  array<string, string>  $headers  lower-case names
     *
     * @throws AbdmHipError
     */
    public static function readCallback(string $type, array $headers, string $rawBody): HipCallback
    {
        $body = json_decode($rawBody, true);

        if (! is_array($body)) {
            throw AbdmHipError::malformed([['field' => 'body', 'message' => 'Not a JSON object.']]);
        }

        $reader = new self($body, $headers);

        try {
            return match ($type) {
                'link-token' => $reader->linkTokenIssued(),
                'care-contexts-linked' => $reader->careContextsLinked(),
                'discover' => $reader->discoveryRequested(),
                'link-init' => $reader->linkRequested(),
                'link-confirm' => $reader->linkConfirmed(),
                'consent-notify' => $reader->consentNotified(),
                'health-information-request' => $reader->healthInformationRequested(),
                default => throw AbdmHipError::malformed([['field' => 'type', 'message' => "Unknown callback type {$type}."]]),
            };
        } catch (AbdmHipError $error) {
            throw $error;
        } catch (Throwable) {
            // A wrongly typed field (e.g. an unparseable date) is a malformed message, not a crash.
            throw AbdmHipError::malformed([['field' => 'body', 'message' => 'A field has the wrong type or format.']]);
        }
    }

    /** @return array<string, mixed> */
    public static function linkTokenRequest(AbhaPatient $patient): array
    {
        return array_filter([
            'abhaNumber' => self::digits($patient->abhaNumber),
            'abhaAddress' => $patient->abhaAddress,
            'name' => $patient->name,
            'gender' => $patient->gender,
            'yearOfBirth' => $patient->yearOfBirth,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function linkCareContexts(AbhaPatient $patient, PatientCareContexts $contexts): array
    {
        return array_filter([
            'abhaNumber' => self::digits($patient->abhaNumber),
            'abhaAddress' => $patient->abhaAddress,
            'patient' => [self::patientEntry($contexts)],
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function notifyCareContext(string $hipId, AbhaPatient $patient, string $patientReference, CareContext $context, CarbonImmutable $recordDate): array
    {
        return [
            'notification' => [
                'patient' => ['id' => $patient->abhaAddress ?? self::digits($patient->abhaNumber)],
                'careContext' => ['patientReference' => $patientReference, 'careContextReference' => $context->reference],
                'hiTypes' => [self::HI_TYPE_DIAGNOSTIC_REPORT],
                'date' => self::timestamp($recordDate),
                'hip' => ['id' => $hipId],
            ],
        ];
    }

    /**
     * @param  list<string>  $matchedBy  abha_number, mobile, uhid
     * @return array<string, mixed>
     */
    public static function onDiscover(DiscoveryRequested $request, ?PatientCareContexts $found, array $matchedBy, ?HipError $error): array
    {
        $identifierTypes = ['abha_number' => 'ABHA_NUMBER', 'mobile' => 'MOBILE', 'uhid' => 'MR'];

        return array_filter([
            'transactionId' => $request->transactionId,
            'patient' => $found === null ? null : [self::patientEntry($found)],
            'matchedBy' => $found === null ? null : array_map(fn (string $by) => $identifierTypes[$by] ?? strtoupper($by), $matchedBy),
            'error' => self::error($error),
            'response' => ['requestId' => $request->requestId],
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function onLinkInit(LinkRequested $request, ?LinkChallenge $challenge, ?HipError $error): array
    {
        return array_filter([
            'transactionId' => $request->transactionId,
            'link' => $challenge === null ? null : [
                'referenceNumber' => $challenge->linkReference,
                'authenticationType' => 'DIRECT',
                'meta' => [
                    'communicationMedium' => 'MOBILE',
                    'communicationHint' => $challenge->communicationHint,
                    'communicationExpiry' => self::timestamp($challenge->expiresAt),
                ],
            ],
            'error' => self::error($error),
            'response' => ['requestId' => $request->requestId],
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function onLinkConfirm(LinkConfirmed $request, ?PatientCareContexts $linked, ?HipError $error): array
    {
        return array_filter([
            'patient' => $linked === null ? null : [self::patientEntry($linked)],
            'error' => self::error($error),
            'response' => ['requestId' => $request->requestId],
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function consentOnNotify(ConsentNotified $notification): array
    {
        return [
            'acknowledgement' => ['status' => 'ok', 'consentId' => $notification->consentArtefactId],
            'response' => ['requestId' => $notification->requestId],
        ];
    }

    /** @return array<string, mixed> */
    public static function healthInformationOnRequest(HealthInformationRequested $request, ?HipError $error): array
    {
        return array_filter([
            'hiRequest' => [
                'transactionId' => $request->transactionId,
                'sessionStatus' => $error === null ? 'ACKNOWLEDGED' : 'ERROR',
            ],
            'error' => self::error($error),
            'response' => ['requestId' => $request->requestId],
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function dataPush(HealthDataPage $page): array
    {
        return [
            'pageNumber' => $page->pageNumber,
            'pageCount' => $page->pageCount,
            'transactionId' => $page->transactionId,
            'entries' => array_map(fn ($entry) => [
                'content' => $entry->content,
                'media' => self::MEDIA_FHIR,
                'checksum' => $entry->checksum,
                'careContextReference' => $entry->careContextReference,
            ], $page->entries),
            'keyMaterial' => [
                'cryptoAlg' => $page->keyMaterial->cryptoAlgorithm,
                'curve' => $page->keyMaterial->curve,
                'dhPublicKey' => [
                    'expiry' => $page->keyMaterial->expiresAt === null ? null : self::timestamp($page->keyMaterial->expiresAt),
                    'parameters' => self::KEY_PARAMETERS,
                    'keyValue' => $page->keyMaterial->publicKey,
                ],
                'nonce' => $page->keyMaterial->nonce,
            ],
        ];
    }

    /**
     * @param  list<CareContextDelivery>  $deliveries
     * @return array<string, mixed>
     */
    public static function healthInformationNotify(string $hipId, string $consentArtefactId, string $transactionId, bool $transferred, array $deliveries, CarbonImmutable $doneAt): array
    {
        return [
            'notification' => [
                'consentId' => $consentArtefactId,
                'transactionId' => $transactionId,
                'doneAt' => self::timestamp($doneAt),
                'notifier' => ['type' => 'HIP', 'id' => $hipId],
                'statusNotification' => [
                    'sessionStatus' => $transferred ? 'TRANSFERRED' : 'FAILED',
                    'hipId' => $hipId,
                    'statusResponses' => array_map(fn (CareContextDelivery $delivery) => [
                        'careContextReference' => $delivery->careContextReference,
                        'hiStatus' => $delivery->delivered ? 'DELIVERED' : 'ERRORED',
                        'description' => $delivery->description,
                    ], $deliveries),
                ],
            ],
        ];
    }

    /** @return array<string, string> */
    public static function headers(string $requestId, ?string $hipId, CarbonImmutable $now, string $cmId): array
    {
        return array_filter([
            'REQUEST-ID' => $requestId,
            'TIMESTAMP' => self::timestamp($now),
            'X-HIP-ID' => $hipId,
            'X-CM-ID' => $cmId,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function __construct(private readonly array $body, private readonly array $headers) {}

    private function linkTokenIssued(): LinkTokenIssued
    {
        return new LinkTokenIssued($this->requestId(), $this->required('response.requestId'), $this->optional('linkToken'), $this->hipError());
    }

    private function careContextsLinked(): CareContextsLinked
    {
        return new CareContextsLinked($this->requestId(), $this->required('response.requestId'), $this->hipError());
    }

    private function discoveryRequested(): DiscoveryRequested
    {
        $verified = $this->identifiers('patient.verifiedIdentifiers');
        $unverified = $this->identifiers('patient.unverifiedIdentifiers');
        $yearOfBirth = $this->value('patient.yearOfBirth');

        return new DiscoveryRequested(
            $this->requestId(),
            $this->required('transactionId'),
            $this->hipId(),
            $this->optional('patient.id'),
            $this->required('patient.name'),
            strtoupper($this->required('patient.gender')),
            is_numeric($yearOfBirth) ? (int) $yearOfBirth : null,
            $verified['MOBILE'] ?? null,
            $verified['ABHA_NUMBER'] ?? $verified['NDHM_HEALTH_NUMBER'] ?? null,
            $unverified['MR'] ?? null,
        );
    }

    private function linkRequested(): LinkRequested
    {
        // v3 sends a list of patients (one per facility reference); earlier versions an object.
        $patients = $this->value('patient');
        $patient = is_array($patients) && array_is_list($patients) ? ($patients[0] ?? null) : $patients;

        if (! is_array($patient) || ! is_string($patient['referenceNumber'] ?? null) || ! is_array($patient['careContexts'] ?? null)) {
            throw AbdmHipError::malformed([['field' => 'patient', 'message' => 'A patient reference and care contexts are required.']]);
        }

        return new LinkRequested(
            $this->requestId(),
            $this->required('transactionId'),
            $this->hipId(),
            $this->optional('abhaAddress') ?? $this->optional('patient.id'),
            $patient['referenceNumber'],
            array_values(array_map(fn ($context) => (string) ($context['referenceNumber'] ?? ''), $patient['careContexts'])),
        );
    }

    private function linkConfirmed(): LinkConfirmed
    {
        return new LinkConfirmed($this->requestId(), $this->hipId(), $this->required('confirmation.linkRefNumber'), $this->required('confirmation.token'));
    }

    private function consentNotified(): ConsentNotified
    {
        $status = strtoupper($this->required('notification.status'));
        $detail = $this->value('notification.consentDetail');
        $artefact = null;

        if ($status === ConsentNotified::GRANTED) {
            if (! is_array($detail)) {
                throw AbdmHipError::malformed([['field' => 'notification.consentDetail', 'message' => 'A granted consent carries its detail.']]);
            }

            $artefact = new ConsentArtefact(
                $this->optional('notification.consentDetail.requester.name') ?? $this->optional('notification.consentDetail.hiu.id') ?? 'ABDM health information user',
                $this->required('notification.consentDetail.purpose.code'),
                $this->optional('notification.consentDetail.patient.id'),
                array_values(array_map(fn ($context) => (string) ($context['careContextReference'] ?? ''), (array) ($detail['careContexts'] ?? []))),
                array_values(array_map('strval', (array) ($detail['hiTypes'] ?? []))),
                $this->date('notification.consentDetail.permission.dateRange.from'),
                $this->date('notification.consentDetail.permission.dateRange.to'),
                $this->optionalDate('notification.consentDetail.permission.dataEraseAt'),
                $this->optional('notification.consentDetail.permission.accessMode') ?? 'VIEW',
            );
        }

        return new ConsentNotified(
            $this->requestId(),
            $this->optional('notification.consentDetail.hip.id') ?? $this->hipId(),
            $this->optional('notification.consentId') ?? $this->required('notification.consentDetail.consentId'),
            $status,
            $artefact,
        );
    }

    private function healthInformationRequested(): HealthInformationRequested
    {
        return new HealthInformationRequested(
            $this->requestId(),
            $this->required('transactionId'),
            $this->hipId(),
            $this->required('hiRequest.consent.id'),
            $this->date('hiRequest.dateRange.from'),
            $this->date('hiRequest.dateRange.to'),
            $this->required('hiRequest.dataPushUrl'),
            new KeyMaterial(
                $this->required('hiRequest.keyMaterial.cryptoAlg'),
                $this->required('hiRequest.keyMaterial.curve'),
                $this->required('hiRequest.keyMaterial.dhPublicKey.keyValue'),
                $this->required('hiRequest.keyMaterial.nonce'),
                $this->optionalDate('hiRequest.keyMaterial.dhPublicKey.expiry'),
            ),
        );
    }

    /** v3 sends the request ID as a header; earlier versions in the body. */
    private function requestId(): string
    {
        return $this->headers['request-id'] ?? $this->required('requestId');
    }

    private function hipId(): string
    {
        return $this->headers['x-hip-id'] ?? $this->required('hipId');
    }

    private function hipError(): ?HipError
    {
        $code = $this->value('error.code');

        return $code === null ? null : new HipError((string) $code, (string) ($this->value('error.message') ?? ''));
    }

    /** @return array<string, string> identifier value by type */
    private function identifiers(string $path): array
    {
        $identifiers = [];

        foreach ((array) $this->value($path) as $identifier) {
            if (is_array($identifier) && is_string($identifier['type'] ?? null) && is_scalar($identifier['value'] ?? null)) {
                $identifiers[strtoupper($identifier['type'])] = (string) $identifier['value'];
            }
        }

        return $identifiers;
    }

    private function required(string $path): string
    {
        $value = $this->value($path);

        if (! is_scalar($value) || (string) $value === '') {
            throw AbdmHipError::malformed([['field' => $path, 'message' => 'Required.']]);
        }

        return (string) $value;
    }

    private function date(string $path): CarbonImmutable
    {
        return $this->optionalDate($path) ?? throw AbdmHipError::malformed([['field' => $path, 'message' => 'Required.']]);
    }

    private function optionalDate(string $path): ?CarbonImmutable
    {
        $value = $this->optional($path);

        if ($value === null) {
            return null;
        }

        if (strtotime($value) === false) {
            throw AbdmHipError::malformed([['field' => $path, 'message' => 'Not a date and time.']]);
        }

        return CarbonImmutable::parse($value);
    }

    private function optional(string $path): ?string
    {
        $value = $this->value($path);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function value(string $path): mixed
    {
        return data_get($this->body, $path);
    }

    /** @return array<string, mixed> */
    private static function patientEntry(PatientCareContexts $patient): array
    {
        return [
            'referenceNumber' => $patient->reference,
            'display' => $patient->display,
            'careContexts' => array_map(fn (CareContext $context) => [
                'referenceNumber' => $context->reference,
                'display' => $context->display,
            ], $patient->careContexts),
            'hiType' => self::HI_TYPE_DIAGNOSTIC_REPORT,
            'count' => count($patient->careContexts),
        ];
    }

    /** @return array{code: string, message: string}|null */
    private static function error(?HipError $error): ?array
    {
        return $error === null ? null : ['code' => $error->code, 'message' => $error->message];
    }

    private static function digits(string $abhaNumber): string
    {
        return preg_replace('/\D/', '', $abhaNumber) ?? '';
    }

    private static function timestamp(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
