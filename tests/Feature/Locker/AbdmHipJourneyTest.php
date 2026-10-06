<?php

namespace Tests\Feature\Locker;

use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Booking\Models\AbdmRequest;
use App\Modules\Lab\Models\Signatory;
use App\Modules\Locker\Domain\FideliusCipher;
use App\Modules\Locker\Jobs\RetryCareContextLinks;
use App\Modules\Locker\Jobs\TransferHealthInformation;
use App\Modules\Locker\Models\AbdmCareContext;
use App\Modules\Locker\Models\AbdmDataTransfer;
use App\Modules\Locker\Models\Consent;
use App\Modules\Locker\Services\HealthInformationTransfers;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Lab\RunsLab;
use Tests\Support\Locker\FillsLocker;
use Tests\Support\Locker\PlaysAbdmGateway;
use Tests\Support\Samples\MovesSamples;
use Tests\TestCase;

/**
 * Phase 8 "done when" stand-in (spec §12: sandbox certification needs ABDM
 * sandbox access): the whole M2 Health Information Provider flow, through
 * our API and ABDM's callbacks, against the fake gateway.
 */
final class AbdmHipJourneyTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use FillsLocker;
    use MovesSamples;
    use PlaysAbdmGateway;
    use RefreshDatabase;
    use RunsLab;

    private const HFR_ID = 'IN1010000101';

    private const HPR_ID = '71-4433-2211-0099';

    private const ABHA_NUMBER = '91-1111-2222-3333';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->useFakeAbdmGateway();
        $this->setUpDemoNetwork();
        CarbonImmutable::setTestNow('2026-10-12 05:00:00');

        // HQ registers the lab in the Health Facility Registry and records its ID.
        $admin = $this->staff(SystemRole::SuperAdmin);
        $labId = $this->branchId('PATCL1');
        $etag = $this->actingAsStaff($admin)->getJson("/api/v1/branches/{$labId}")->assertOk()->headers->get('ETag');
        $this->actingAsStaff($admin)->patchJson("/api/v1/branches/{$labId}", ['hfr_id' => self::HFR_ID], ['If-Match' => (string) $etag])->assertOk();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_released_report_reaches_a_health_information_user_under_the_patients_consent(): void
    {
        // Asha's ABHA is verified at the desk; her pathologist is in the Health Professional Registry.
        $this->actingAsStaff($this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1'));
        $asha = $this->registerPatient(['age_years' => 36]);
        $this->linkAbhaAtDesk($asha['id'], self::ABHA_NUMBER);
        $pathologist = $this->lockerPathologist('PATCL1');
        $this->asSystem(fn () => Signatory::query()->where('user_id', $pathologist->id)->update(['hpr_id' => self::HPR_ID]));

        $report = $this->releasedCbc($asha['id'], 'PATPSC1', 'PATCL1', '11.2');

        // 1. On release, the lab asks ABDM for a token to link to Asha's ABHA.
        $tokenRequest = $this->lastSentToAbdm('generate_link_token');
        $this->assertSame(self::HFR_ID, $tokenRequest['headers']['X-HIP-ID']);
        $this->assertSame('sbx', $tokenRequest['headers']['X-CM-ID']);
        $this->assertSame(['abhaNumber' => '91111122223333', 'name' => 'Asha Kumari', 'gender' => 'F', 'yearOfBirth' => 1990], $tokenRequest['body']);
        $this->assertSame('pending', $this->careContext($report['id'])->link_status->value);

        // 2. The token arrives; the report is linked with it.
        $this->abdmCallback('link-token', $this->abdmFixture('on-generate-token', [
            'response.requestId' => $tokenRequest['headers']['REQUEST-ID'],
        ]), self::HFR_ID)->assertStatus(202);

        $link = $this->lastSentToAbdm('link_care_contexts');
        $this->assertSame('eyJhbGciOiJSUzUxMiJ9.link-token-for-asha', $link['headers']['X-LINK-TOKEN']);
        $this->assertSame($asha['uhid'], $link['body']['patient'][0]['referenceNumber']);
        $this->assertSame([['referenceNumber' => $report['id'], 'display' => 'Lab report, 12 Oct 2026, Patna Clinical Lab']], $link['body']['patient'][0]['careContexts']);
        $this->assertSame('DiagnosticReport', $link['body']['patient'][0]['hiType']);

        // 3. ABDM confirms; Asha's ABHA apps are told a new record exists.
        $this->abdmCallback('care-contexts-linked', $this->abdmFixture('on-carecontext', [
            'response.requestId' => $link['headers']['REQUEST-ID'],
        ]), self::HFR_ID)->assertStatus(202);

        $this->assertSame('linked', $this->careContext($report['id'])->link_status->value);
        $this->assertSame($report['id'], $this->lastSentToAbdm('notify_care_context')['body']['notification']['careContext']['careContextReference']);

        // 4. In her ABHA app, Asha lets Dr Sinha's clinic see it: the consent artefact is mirrored.
        $this->abdmCallback('consent-notify', $this->grantedConsent($asha['uhid'], $report['id']), self::HFR_ID)->assertStatus(202);
        $this->assertSame(['status' => 'ok', 'consentId' => 'c0ns3nt-0000-4000-8000-000000000001'], $this->lastSentToAbdm('consent_on_notify')['body']['acknowledgement']);

        $token = $this->signInByPhone('9876501234');
        $consents = $this->asPerson($token)->getJson('/api/v1/me/abdm-consents')->assertOk()->json('data');
        $this->assertCount(1, $consents);
        $this->assertSame(['Dr. Meera Sinha', 'treatment', 'CAREMGT', 'granted', ['DiagnosticReport']], [
            $consents[0]['requester'], $consents[0]['purpose'], $consents[0]['purpose_code'], $consents[0]['status'], $consents[0]['hi_types'],
        ]);
        $this->assertCount(1, $consents[0]['medical_record_ids']);
        // Her own shares stay her own: an ABDM consent is managed in the ABHA app.
        $this->asPerson($token)->getJson('/api/v1/me/shares')->assertOk()->assertJsonCount(0, 'data');

        // 5. The clinic's system asks for the data with its one-time key.
        $hiu = FideliusCipher::keyPair();
        $hiuNonce = FideliusCipher::nonce();
        $this->abdmCallback('health-information-request', $this->dataRequest('txn-1', $hiu['public'], $hiuNonce), self::HFR_ID)->assertStatus(202);
        $this->assertSame(['transactionId' => 'txn-1', 'sessionStatus' => 'ACKNOWLEDGED'], $this->lastSentToAbdm('health_information_on_request')['body']['hiRequest']);

        // The record goes straight to the clinic, encrypted for its key only.
        $push = $this->lastSentToAbdm('data_push');
        $this->assertSame('https://hiu.example.in/data/push', $push['url']);
        $this->assertSame(['pageNumber' => 1, 'pageCount' => 1, 'transactionId' => 'txn-1'], array_intersect_key($push['body'], array_flip(['pageNumber', 'pageCount', 'transactionId'])));
        $entry = $push['body']['entries'][0];
        $this->assertSame([$report['id'], 'application/fhir+json'], [$entry['careContextReference'], $entry['media']]);
        $this->assertSame(['ECDH', 'Curve25519'], [$push['body']['keyMaterial']['cryptoAlg'], $push['body']['keyMaterial']['curve']]);

        $document = FideliusCipher::decrypt($entry['content'], $hiu['private'], $push['body']['keyMaterial']['dhPublicKey']['keyValue'], $hiuNonce, $push['body']['keyMaterial']['nonce']);
        $this->assertSame(md5($document), $entry['checksum']);
        $resources = $this->resourcesByType(json_decode($document, true));

        $this->assertSame('Composition', $resources['Composition'][0]['resourceType']);
        $this->assertSame([$asha['uhid'], self::ABHA_NUMBER], array_column($resources['Patient'][0]['identifier'], 'value'));
        $this->assertSame(self::HFR_ID, $resources['Organization'][0]['identifier'][0]['value']);
        $this->assertSame(self::HPR_ID, $resources['Practitioner'][0]['identifier'][0]['value']);
        $this->assertSame(['http://loinc.org', '58410-2'], [$resources['DiagnosticReport'][0]['code']['coding'][0]['system'], $resources['DiagnosticReport'][0]['code']['coding'][0]['code']]);
        $haemoglobin = collect($resources['Observation'])->firstWhere('code.coding.0.code', '718-7');
        $this->assertSame(['value' => 11.2, 'unit' => 'g/dL'], $haemoglobin['valueQuantity']);
        $this->assertSame('L', $haemoglobin['interpretation'][0]['coding'][0]['code']);
        // The signed PDF travels exactly as the lab released it.
        $this->assertSame($report['pdf_sha256'], hash('sha256', (string) base64_decode($resources['DocumentReference'][0]['content'][0]['attachment']['data'])));

        $notice = $this->lastSentToAbdm('health_information_notify')['body']['notification'];
        $this->assertSame('TRANSFERRED', $notice['statusNotification']['sessionStatus']);
        $this->assertSame([['careContextReference' => $report['id'], 'hiStatus' => 'DELIVERED', 'description' => 'Delivered']], $notice['statusNotification']['statusResponses']);

        // 6. Asha sees that her record was shared through ABDM.
        $accessLog = $this->asPerson($token)->getJson('/api/v1/me/record-access-logs')->assertOk()->json('data');
        $this->assertContains(['abdm', 'share'], array_map(fn (array $row) => [$row['actor_type'], $row['action']], $accessLog));

        // 7. She revokes the consent in her app: from then on nothing is sent.
        $this->abdmCallback('consent-notify', $this->abdmFixture('consent-notify-revoked'), self::HFR_ID)->assertStatus(202);
        $this->assertSame('revoked', $this->asSystem(fn () => Consent::query()->where('consent_artefact_id', 'c0ns3nt-0000-4000-8000-000000000001')->firstOrFail()->status->value));

        $this->abdmCallback('health-information-request', $this->dataRequest('txn-2', $hiu['public'], $hiuNonce), self::HFR_ID)->assertStatus(202);
        $refused = $this->lastSentToAbdm('health_information_on_request')['body'];
        $this->assertSame(['ERROR', 'CONSENT_NOT_IN_FORCE'], [$refused['hiRequest']['sessionStatus'], $refused['error']['code']]);
        $this->assertCount(1, $this->abdm()->sentTo('data_push'));

        // ABHA numbers and link tokens never reach our ABDM log.
        $log = $this->asSystem(fn () => AbdmRequest::query()->get()->toJson());
        $this->assertStringNotContainsString('91111122223333', $log);
        $this->assertStringNotContainsString('link-token-for-asha', $log);
    }

    public function test_a_patient_links_their_reports_from_their_abha_app_with_a_code_sent_to_their_phone(): void
    {
        // Asha never linked her ABHA at the desk: nothing is linked on release.
        $this->actingAsStaff($this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1'));
        $asha = $this->registerPatient(['age_years' => 36]);
        $report = $this->releasedCbc($asha['id'], 'PATPSC1', 'PATCL1', '11.2');
        $this->assertSame([], $this->abdm()->sentTo('generate_link_token'));

        // In her ABHA app she searches the lab with her verified mobile.
        $this->abdmCallback('discover', $this->abdmFixture('discover', [
            'transactionId' => 'disc-1',
            'patient.verifiedIdentifiers' => [['type' => 'MOBILE', 'value' => '9876501234']],
            'patient.unverifiedIdentifiers' => [],
        ]), self::HFR_ID)->assertStatus(202);

        $found = $this->lastSentToAbdm('on_discover')['body'];
        $this->assertSame(['disc-1', $asha['uhid'], ['MOBILE']], [$found['transactionId'], $found['patient'][0]['referenceNumber'], $found['matchedBy']]);
        $this->assertSame($report['id'], $found['patient'][0]['careContexts'][0]['referenceNumber']);

        // She picks the report; a code goes to the phone on our record.
        $this->messages->clear();
        $this->abdmCallback('link-init', $this->abdmFixture('link-init', [
            'transactionId' => 'disc-1',
            'patient.0.referenceNumber' => $asha['uhid'],
            'patient.0.careContexts' => [['referenceNumber' => $report['id']]],
        ]), self::HFR_ID)->assertStatus(202);

        $challenge = $this->lastSentToAbdm('on_link_init')['body']['link'];
        $this->assertSame(['DIRECT', 'MOBILE', '******1234'], [$challenge['authenticationType'], $challenge['meta']['communicationMedium'], $challenge['meta']['communicationHint']]);
        $code = $this->lastOtpCodeSentTo('9876501234');

        // A wrong code links nothing.
        $this->abdmCallback('link-confirm', $this->abdmFixture('link-confirm', [
            'confirmation.linkRefNumber' => $challenge['referenceNumber'],
            'confirmation.token' => $code === '000000' ? '111111' : '000000',
        ]), self::HFR_ID)->assertStatus(202);
        $this->assertSame('OTP_INVALID', $this->lastSentToAbdm('on_link_confirm')['body']['error']['code']);
        $this->assertNull($this->careContext($report['id']));

        // The right one links the report.
        $this->abdmCallback('link-confirm', $this->abdmFixture('link-confirm', [
            'confirmation.linkRefNumber' => $challenge['referenceNumber'],
            'confirmation.token' => $code,
        ]), self::HFR_ID)->assertStatus(202);

        $confirmed = $this->lastSentToAbdm('on_link_confirm')['body'];
        $this->assertArrayNotHasKey('error', $confirmed);
        $this->assertSame([$asha['uhid'], $report['id']], [$confirmed['patient'][0]['referenceNumber'], $confirmed['patient'][0]['careContexts'][0]['referenceNumber']]);
        $this->assertSame('linked', $this->careContext($report['id'])?->link_status->value);

        // Searching again offers nothing new.
        $this->abdmCallback('discover', $this->abdmFixture('discover', [
            'transactionId' => 'disc-2',
            'patient.verifiedIdentifiers' => [['type' => 'MOBILE', 'value' => '9876501234']],
        ]), self::HFR_ID)->assertStatus(202);
        $this->assertSame('CARE_CONTEXTS_NOT_FOUND', $this->lastSentToAbdm('on_discover')['body']['error']['code']);
    }

    public function test_discovery_never_reveals_someone_elses_records(): void
    {
        $this->actingAsStaff($this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1'));
        $asha = $this->registerPatient(['age_years' => 36]);
        $this->releasedCbc($asha['id'], 'PATPSC1', 'PATCL1', '11.2');

        $search = fn (string $transactionId, array $overrides) => $this->abdmCallback('discover', $this->abdmFixture('discover', [
            'transactionId' => $transactionId,
            'patient.verifiedIdentifiers' => [['type' => 'MOBILE', 'value' => '9876501234']],
            ...$overrides,
        ]), self::HFR_ID)->assertStatus(202);

        // Same phone, but a man of another name: no match.
        $search('d-1', ['patient.name' => 'Mohan Lal', 'patient.gender' => 'M']);
        $this->assertSame('PATIENT_NOT_FOUND', $this->lastSentToAbdm('on_discover')['body']['error']['code']);
        $this->assertArrayNotHasKey('patient', $this->lastSentToAbdm('on_discover')['body']);

        // Right name, but born twenty years apart: no match.
        $search('d-2', ['patient.yearOfBirth' => 1970]);
        $this->assertSame('PATIENT_NOT_FOUND', $this->lastSentToAbdm('on_discover')['body']['error']['code']);

        // A facility that is not ours.
        $this->abdmCallback('discover', $this->abdmFixture('discover', ['transactionId' => 'd-3']), 'IN9999999999')->assertStatus(202);
        $this->assertSame('UNKNOWN_FACILITY', $this->lastSentToAbdm('on_discover')['body']['error']['code']);

        // Linking records that were never offered in this search.
        $this->abdmCallback('link-init', $this->abdmFixture('link-init', ['transactionId' => 'd-1']), self::HFR_ID)->assertStatus(202);
        $this->assertSame('LINK_SESSION_EXPIRED', $this->lastSentToAbdm('on_link_init')['body']['error']['code']);
    }

    public function test_data_requests_outside_the_consent_are_refused_and_revocation_stops_a_queued_transfer(): void
    {
        $this->actingAsStaff($this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1'));
        $asha = $this->registerPatient(['age_years' => 36]);
        $this->linkAbhaAtDesk($asha['id'], self::ABHA_NUMBER);
        $report = $this->releasedCbc($asha['id'], 'PATPSC1', 'PATCL1', '11.2');
        $this->linkThroughAbdm();
        $this->abdmCallback('consent-notify', $this->grantedConsent($asha['uhid'], $report['id']), self::HFR_ID)->assertStatus(202);
        $hiu = FideliusCipher::keyPair();

        // Dates beyond the consent.
        $this->abdmCallback('health-information-request', $this->dataRequest('txn-wide', $hiu['public'], FideliusCipher::nonce(), [
            'hiRequest.dateRange.to' => '2027-06-30T00:00:00.000Z',
        ]), self::HFR_ID)->assertStatus(202);
        $this->assertSame('DATE_RANGE_OUTSIDE_CONSENT', $this->lastSentToAbdm('health_information_on_request')['body']['error']['code']);

        // A key we cannot use.
        $this->abdmCallback('health-information-request', $this->dataRequest('txn-key', 'bm90LWEta2V5', FideliusCipher::nonce()), self::HFR_ID)->assertStatus(202);
        $this->assertSame('KEY_MATERIAL_UNSUPPORTED', $this->lastSentToAbdm('health_information_on_request')['body']['error']['code']);

        // A consent we never heard of.
        $this->abdmCallback('health-information-request', $this->dataRequest('txn-unknown', $hiu['public'], FideliusCipher::nonce(), [
            'hiRequest.consent.id' => 'some-other-consent',
        ]), self::HFR_ID)->assertStatus(202);
        $this->assertSame('CONSENT_NOT_FOUND', $this->lastSentToAbdm('health_information_on_request')['body']['error']['code']);
        $this->assertSame([], $this->abdm()->sentTo('data_push'));
        $this->assertSame(['rejected'], $this->asSystem(fn () => AbdmDataTransfer::query()->pluck('status')->map->value->unique()->values()->all()));

        // A valid request is acknowledged, but the consent is revoked before the transfer runs.
        $transferId = $this->withoutTransfers(function () use ($hiu): string {
            $this->abdmCallback('health-information-request', $this->dataRequest('txn-late', $hiu['public'], FideliusCipher::nonce()), self::HFR_ID)->assertStatus(202);
            $this->abdmCallback('consent-notify', $this->abdmFixture('consent-notify-revoked'), self::HFR_ID)->assertStatus(202);

            return $this->asSystem(fn () => (string) AbdmDataTransfer::query()->where('transaction_id', 'txn-late')->value('id'));
        });
        $this->asSystem(fn () => app(HealthInformationTransfers::class)->transfer($transferId));

        $this->assertSame([], $this->abdm()->sentTo('data_push'));
        $this->assertSame(['failed', 'CONSENT_NOT_IN_FORCE'], $this->asSystem(function () use ($transferId): array {
            $transfer = AbdmDataTransfer::query()->findOrFail($transferId);

            return [$transfer->status->value, $transfer->error_code];
        }));
        $this->assertSame('FAILED', $this->lastSentToAbdm('health_information_notify')['body']['notification']['statusNotification']['sessionStatus']);
    }

    public function test_callbacks_must_be_signed_well_formed_and_are_acted_on_once(): void
    {
        $body = $this->abdmFixture('discover');

        $this->abdmCallback('discover', $body, self::HFR_ID, signature: 'forged')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_SIGNATURE');

        $this->abdmCallback('discover', $this->abdmFixture('discover', ['patient.name' => null]), self::HFR_ID)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'ABDM_CALLBACK_MALFORMED')
            ->assertJsonPath('error.details.0.field', 'patient.name');

        $this->abdmCallback('profile-update', $body, self::HFR_ID)->assertNotFound();

        // The gateway retries with the same request ID: answered once.
        $this->abdmCallback('discover', $body, self::HFR_ID, requestId: 'req-retry')->assertStatus(202);
        $this->abdmCallback('discover', $body, self::HFR_ID, requestId: 'req-retry')->assertStatus(202);
        $this->assertCount(1, $this->abdm()->sentTo('on_discover'));
    }

    public function test_unanswered_and_failed_links_are_retried(): void
    {
        $this->actingAsStaff($this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1'));
        $asha = $this->registerPatient(['age_years' => 36]);
        $this->linkAbhaAtDesk($asha['id'], self::ABHA_NUMBER);

        // ABDM is down when the report is released.
        $this->abdm()->unavailable = true;
        $report = $this->releasedCbc($asha['id'], 'PATPSC1', 'PATCL1', '11.2');
        $this->assertSame([], $this->abdm()->sentTo('generate_link_token'));
        $this->assertSame('pending', $this->careContext($report['id'])?->link_status->value);

        // Back up: the hourly job asks again.
        $this->abdm()->unavailable = false;
        $this->retryLinks();
        $tokenRequest = $this->lastSentToAbdm('generate_link_token');

        // ABDM refuses the link: failed, then retried with a fresh token request.
        $this->abdmCallback('link-token', $this->abdmFixture('on-generate-token', ['response.requestId' => $tokenRequest['headers']['REQUEST-ID']]), self::HFR_ID)->assertStatus(202);
        $link = $this->lastSentToAbdm('link_care_contexts');
        $this->abdmCallback('care-contexts-linked', $this->abdmFixture('on-carecontext', [
            'response.requestId' => $link['headers']['REQUEST-ID'],
            'error' => ['code' => 'ABDM-1092', 'message' => 'Link token expired'],
        ]), self::HFR_ID)->assertStatus(202);
        $this->assertSame(['failed', 'ABDM-1092'], [$this->careContext($report['id'])?->link_status->value, $this->careContext($report['id'])?->error_code]);

        $this->retryLinks();
        $this->assertCount(2, $this->abdm()->sentTo('generate_link_token'));
        $this->assertSame('pending', $this->careContext($report['id'])?->link_status->value);
    }

    /** The hourly retry job, run now. */
    private function retryLinks(): void
    {
        $this->asSystem(fn () => app()->call([new RetryCareContextLinks, 'handle']));
    }

    private function careContext(string $reportId): ?AbdmCareContext
    {
        return $this->asSystem(fn () => AbdmCareContext::query()->where('report_id', $reportId)->first());
    }

    /** Plays ABDM's side of HIP-initiated linking for the last report released. */
    private function linkThroughAbdm(): void
    {
        $this->abdmCallback('link-token', $this->abdmFixture('on-generate-token', [
            'response.requestId' => $this->lastSentToAbdm('generate_link_token')['headers']['REQUEST-ID'],
        ]), self::HFR_ID)->assertStatus(202);
        $this->abdmCallback('care-contexts-linked', $this->abdmFixture('on-carecontext', [
            'response.requestId' => $this->lastSentToAbdm('link_care_contexts')['headers']['REQUEST-ID'],
        ]), self::HFR_ID)->assertStatus(202);
    }

    /** @return array<string, mixed> */
    private function grantedConsent(string $uhid, string $reportId): array
    {
        return $this->abdmFixture('consent-notify-granted', [
            'notification.consentDetail.hip.id' => self::HFR_ID,
            'notification.consentDetail.careContexts' => [['patientReference' => $uhid, 'careContextReference' => $reportId]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function dataRequest(string $transactionId, string $publicKey, string $nonce, array $overrides = []): array
    {
        return $this->abdmFixture('health-information-request', [
            'transactionId' => $transactionId,
            'hiRequest.keyMaterial.dhPublicKey.keyValue' => $publicKey,
            'hiRequest.keyMaterial.nonce' => $nonce,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, list<array<string, mixed>>>
     */
    private function resourcesByType(array $bundle): array
    {
        $this->assertSame(['Bundle', 'document'], [$bundle['resourceType'], $bundle['type']]);
        $byType = [];

        foreach ($bundle['entry'] as $entry) {
            $byType[$entry['resource']['resourceType']][] = $entry['resource'];
        }

        return $byType;
    }

    /**
     * Runs the callback with transfers left queued instead of run at once.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withoutTransfers(callable $callback): mixed
    {
        Bus::fake([TransferHealthInformation::class]);

        return $callback();
    }

    private function lastOtpCodeSentTo(string $phone): string
    {
        $codes = [];

        foreach ($this->messages->sent as $message) {
            if ($message['channel'] === 'sms' && $message['to'] === $phone && preg_match('/^(\d{6}) is your code to link/', $message['body'], $match) === 1) {
                $codes[] = $match[1];
            }
        }

        $this->assertNotEmpty($codes, "No link code was sent to {$phone}.");

        return $codes[array_key_last($codes)];
    }
}
