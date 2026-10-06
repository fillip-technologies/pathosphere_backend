<?php

namespace App\Modules\Locker\Services;

use App\Modules\Booking\Enums\AbdmRequestStatus;
use App\Modules\Booking\Services\AbdmRequestLog;
use App\Modules\Booking\Services\PeopleDirectory;
use App\Modules\Lab\Services\ReleasedReports;
use App\Modules\Lab\Services\ReleasedResult;
use App\Modules\Locker\Contracts\Abdm\CareContextDelivery;
use App\Modules\Locker\Contracts\Abdm\EncryptedEntry;
use App\Modules\Locker\Contracts\Abdm\HealthDataPage;
use App\Modules\Locker\Contracts\Abdm\HealthInformationRequested;
use App\Modules\Locker\Contracts\Abdm\HipError;
use App\Modules\Locker\Contracts\Abdm\KeyMaterial;
use App\Modules\Locker\Contracts\AbdmHipGateway;
use App\Modules\Locker\Domain\BundleSubject;
use App\Modules\Locker\Domain\DiagnosticReportRecord;
use App\Modules\Locker\Domain\FideliusCipher;
use App\Modules\Locker\Enums\AccessAction;
use App\Modules\Locker\Enums\AccessActorType;
use App\Modules\Locker\Enums\CareContextLinkStatus;
use App\Modules\Locker\Enums\DataTransferStatus;
use App\Modules\Locker\Errors\AbdmHipError;
use App\Modules\Locker\Jobs\TransferHealthInformation;
use App\Modules\Locker\Models\AbdmCareContext;
use App\Modules\Locker\Models\AbdmDataTransfer;
use App\Modules\Locker\Models\Consent;
use App\Modules\Locker\Models\PathologyReport;
use App\Modules\Locker\Models\PathologyResult;
use App\Modules\Locker\StateMachines\DataTransferStateMachine;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Sends records to a health information user under a consent artefact
 * (spec §5.7 M2, §10: share only within a granted artefact, log every
 * share, honour revocation immediately).
 *
 * The request is checked and acknowledged at once; the records are built,
 * encrypted and pushed by a queued job, which checks the consent again just
 * before sending. Each care context travels as one encrypted FHIR document,
 * each sent record is written to record_access_logs, and ABDM is told what
 * was delivered.
 */
final class HealthInformationTransfers
{
    public function __construct(
        private readonly AbdmHipGateway $gateway,
        private readonly AbdmRequestLog $requestLog,
        private readonly PeopleDirectory $people,
        private readonly ReleasedReports $releasedReports,
        private readonly RecordAccessLogger $accessLogger,
        private readonly DataTransferStateMachine $states,
    ) {}

    public function requested(HealthInformationRequested $request): ?HipError
    {
        $consent = Consent::query()->where('consent_artefact_id', $request->consentArtefactId)->first();
        $error = $this->refusal($request, $consent);

        try {
            $transfer = new AbdmDataTransfer([
                'transaction_id' => $request->transactionId,
                'request_id' => $request->requestId,
                'consent_artefact_id' => $request->consentArtefactId,
                'hip_id' => $request->hipId,
                'date_from' => $request->dateFrom,
                'date_to' => $request->dateTo,
                'data_push_url' => $request->dataPushUrl,
                'key_material' => [
                    'crypto_algorithm' => $request->keyMaterial->cryptoAlgorithm,
                    'curve' => $request->keyMaterial->curve,
                    'public_key' => $request->keyMaterial->publicKey,
                    'nonce' => $request->keyMaterial->nonce,
                    'expires_at' => $request->keyMaterial->expiresAt?->utc()->toIso8601ZuluString(),
                ],
                'status' => $error === null ? DataTransferStatus::Acknowledged : DataTransferStatus::Rejected,
                'error_code' => $error?->code,
            ]);
            $transfer->consent_id = $consent?->id;
            $transfer->save();
        } catch (UniqueConstraintViolationException) {
            // The same transaction asked twice: it is already being served.
            return null;
        }

        $requestId = (string) Str::uuid();
        $this->requestLog->outbound('health_information_on_request', $requestId, AbdmRequestStatus::Pending, $consent?->patient_id, txnId: $request->transactionId, errorCode: $error?->code);

        try {
            $this->gateway->answerHealthInformationRequest($requestId, $request, $error);
            $this->requestLog->settle($requestId, AbdmRequestStatus::Success, $error?->code);
        } catch (AbdmHipError $failure) {
            $this->requestLog->settle($requestId, AbdmRequestStatus::Failed, $failure->errorCode);
        }

        $organizationId = $consent === null ? null : $this->people->patient($consent->patient_id)?->organizationId;

        if ($error === null && $organizationId !== null) {
            TransferHealthInformation::dispatch($transfer->id, $organizationId)->afterCommit();
        }

        return $error;
    }

    /** Builds, encrypts and pushes the records of one acknowledged request. */
    public function transfer(string $transferId): void
    {
        $transfer = AbdmDataTransfer::query()->with('consent')->find($transferId);

        if ($transfer === null || $transfer->status !== DataTransferStatus::Acknowledged) {
            return;
        }

        $consent = $transfer->consent;

        // Revoked or expired since the request arrived: nothing leaves.
        if ($consent === null || ! $consent->isInForce()) {
            $this->finish($transfer, false, 'CONSENT_NOT_IN_FORCE', []);

            return;
        }

        $careContexts = AbdmCareContext::query()
            ->whereIn('care_context_reference', (array) ($consent->scope['care_context_references'] ?? []))
            ->where('patient_id', $consent->patient_id)
            ->where('link_status', CareContextLinkStatus::Linked)
            ->with('record')
            ->orderBy('created_at')
            ->get()
            // Only records dated within the range asked for.
            ->filter(fn (AbdmCareContext $careContext) => $careContext->record->record_date->betweenIncluded(
                $transfer->date_from->startOfDay(),
                $transfer->date_to->endOfDay(),
            ))
            ->values();

        $keys = FideliusCipher::keyPair();
        $nonce = FideliusCipher::nonce();
        $theirs = $transfer->key_material;
        $entries = [];
        $deliveries = [];

        foreach ($careContexts as $careContext) {
            $document = $this->document($careContext);

            if ($document === null) {
                $deliveries[$careContext->care_context_reference] = new CareContextDelivery($careContext->care_context_reference, false, 'The record could not be prepared.');

                continue;
            }

            $entries[] = new EncryptedEntry(
                $careContext->care_context_reference,
                FideliusCipher::encrypt($document, $keys['private'], $theirs['public_key'], $nonce, $theirs['nonce']),
                md5($document),
            );
        }

        $ourKey = new KeyMaterial(
            FideliusCipher::ALGORITHM,
            FideliusCipher::CURVE,
            $keys['public'],
            $nonce,
            CarbonImmutable::now()->addMinutes((int) config('pathology.abdm.transfer_key_valid_minutes')),
        );
        $pages = array_chunk($entries, max(1, (int) config('pathology.abdm.transfer_page_size')));
        $delivered = [];

        try {
            foreach ($pages as $index => $page) {
                $this->gateway->pushHealthData($transfer->data_push_url, new HealthDataPage($transfer->transaction_id, $index + 1, count($pages), $page, $ourKey));

                foreach ($page as $entry) {
                    $delivered[] = $entry->careContextReference;
                }
            }
        } catch (AbdmHipError) {
            // Pages sent before the failure did arrive; the rest are reported as not delivered.
        }

        foreach ($entries as $entry) {
            $deliveries[$entry->careContextReference] = in_array($entry->careContextReference, $delivered, true)
                ? new CareContextDelivery($entry->careContextReference, true, 'Delivered')
                : new CareContextDelivery($entry->careContextReference, false, 'The receiver could not be reached.');
        }

        $sentRecordIds = $careContexts
            ->filter(fn (AbdmCareContext $careContext) => in_array($careContext->care_context_reference, $delivered, true))
            ->pluck('medical_record_id')
            ->all();
        $this->accessLogger->log($sentRecordIds, AccessActorType::Abdm, null, AccessAction::Share);

        $succeeded = $entries === [] || count($delivered) > 0;
        $this->finish($transfer, $succeeded, $succeeded ? null : 'DATA_PUSH_FAILED', array_values($deliveries), count($delivered));
    }

    private function refusal(HealthInformationRequested $request, ?Consent $consent): ?HipError
    {
        $scope = $consent->scope ?? [];

        return match (true) {
            $consent === null => new HipError('CONSENT_NOT_FOUND', 'No consent with this ID was notified to us.'),
            ! $consent->isInForce() => new HipError('CONSENT_NOT_IN_FORCE', 'The consent has been revoked or has expired.'),
            ! in_array(AbdmCareContext::HI_TYPE, (array) ($scope['hi_types'] ?? []), true) => new HipError('HI_TYPE_NOT_SUPPORTED', 'The consent does not cover diagnostic reports.'),
            $request->dateFrom->lt(CarbonImmutable::parse((string) $scope['date_from'])),
            $request->dateTo->gt(CarbonImmutable::parse((string) $scope['date_to'])) => new HipError('DATE_RANGE_OUTSIDE_CONSENT', 'The dates asked for are outside the consent.'),
            $request->keyMaterial->cryptoAlgorithm !== FideliusCipher::ALGORITHM,
            strcasecmp($request->keyMaterial->curve, FideliusCipher::CURVE) !== 0,
            ! FideliusCipher::accepts($request->keyMaterial->publicKey, $request->keyMaterial->nonce) => new HipError('KEY_MATERIAL_UNSUPPORTED', 'The encryption keys are not usable.'),
            $request->keyMaterial->expiresAt !== null && $request->keyMaterial->expiresAt->isPast() => new HipError('KEY_MATERIAL_EXPIRED', 'The encryption key has expired.'),
            default => null,
        };
    }

    /** The care context as a FHIR document (JSON), read from the locker's copy of that version. */
    private function document(AbdmCareContext $careContext): ?string
    {
        $report = $this->releasedReports->forExchange($careContext->organization_id, $careContext->report_id);
        $copy = PathologyReport::query()->where('report_id', $careContext->report_id)->with('results')->first();
        $patient = $this->people->patient($careContext->patient_id);

        if ($report === null || $copy === null || $patient === null) {
            return null;
        }

        $identity = $this->people->abhaIdentity($careContext->patient_id);
        $results = $copy->results->map(fn (PathologyResult $result) => new ReleasedResult(
            $result->test_code,
            $result->test_name,
            $result->parameter_code,
            $result->parameter_name,
            $result->value,
            $result->value_numeric,
            $result->unit,
            $result->reference_range,
            $result->flag,
        ))->values()->all();

        $bundle = DiagnosticReportRecord::bundle(
            $report,
            new BundleSubject($patient->uhid, $patient->name, $patient->gender, $patient->dob, $identity?->abhaNumber, $identity?->abhaAddress),
            $results,
            $report->pdfReady ? $this->releasedReports->pdfContents($careContext->organization_id, $careContext->report_id) : null,
            CarbonImmutable::now(),
            rtrim((string) config('app.url'), '/').'/fhir',
        );

        return (string) json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param  list<CareContextDelivery>  $deliveries */
    private function finish(AbdmDataTransfer $transfer, bool $transferred, ?string $errorCode, array $deliveries, int $delivered = 0): void
    {
        $this->states->transition($transfer, $transferred ? DataTransferStatus::Transferred : DataTransferStatus::Failed, [
            'error_code' => $errorCode,
            'care_context_count' => $delivered,
            'transferred_at' => $transferred ? CarbonImmutable::now() : null,
        ]);

        $requestId = (string) Str::uuid();
        $this->requestLog->outbound('health_information_notify', $requestId, AbdmRequestStatus::Pending, $transfer->consent?->patient_id, txnId: $transfer->transaction_id, errorCode: $errorCode, payloadMasked: [
            'delivered' => $delivered,
            'not_delivered' => count(array_filter($deliveries, fn (CareContextDelivery $delivery) => ! $delivery->delivered)),
        ]);

        try {
            $this->gateway->notifyTransfer($requestId, $transfer->hip_id, $transfer->consent_artefact_id, $transfer->transaction_id, $transferred, $deliveries);
            $this->requestLog->settle($requestId, AbdmRequestStatus::Success);
        } catch (AbdmHipError $failure) {
            $this->requestLog->settle($requestId, AbdmRequestStatus::Failed, $failure->errorCode);
        }
    }
}
