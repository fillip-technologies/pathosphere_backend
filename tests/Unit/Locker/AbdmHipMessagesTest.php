<?php

namespace Tests\Unit\Locker;

use App\Modules\Locker\Contracts\Abdm\CareContext;
use App\Modules\Locker\Contracts\Abdm\CareContextsLinked;
use App\Modules\Locker\Contracts\Abdm\ConsentNotified;
use App\Modules\Locker\Contracts\Abdm\DiscoveryRequested;
use App\Modules\Locker\Contracts\Abdm\HealthInformationRequested;
use App\Modules\Locker\Contracts\Abdm\HipError;
use App\Modules\Locker\Contracts\Abdm\LinkChallenge;
use App\Modules\Locker\Contracts\Abdm\LinkConfirmed;
use App\Modules\Locker\Contracts\Abdm\LinkRequested;
use App\Modules\Locker\Contracts\Abdm\LinkTokenIssued;
use App\Modules\Locker\Contracts\Abdm\PatientCareContexts;
use App\Modules\Locker\Errors\AbdmHipError;
use App\Modules\Locker\Infrastructure\AbdmHipMessages;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for ABDM's HIP messages: every payload in
 * tests/Fixtures/Abdm reads into our types, and our answers are written in
 * ABDM's shape. Replace the fixtures with recorded sandbox traffic during
 * onboarding; a change in ABDM's format then fails here first.
 */
final class AbdmHipMessagesTest extends TestCase
{
    private const HEADERS = ['request-id' => 'req-1', 'x-hip-id' => 'IN1010000101'];

    public function test_answers_to_our_own_requests_name_the_request_they_answer(): void
    {
        $token = $this->read('link-token', 'on-generate-token');
        $this->assertInstanceOf(LinkTokenIssued::class, $token);
        $this->assertSame(['req-1', '5f7a535d-a3fd-416b-b069-c97d021fbacd', 'eyJhbGciOiJSUzUxMiJ9.link-token-for-asha'], [$token->requestId, $token->respondingToRequestId, $token->linkToken]);
        $this->assertNull($token->error);

        $linked = $this->read('care-contexts-linked', 'on-carecontext');
        $this->assertInstanceOf(CareContextsLinked::class, $linked);
        $this->assertSame('3a1f5b2e-7c4d-4e8f-9a0b-1c2d3e4f5a6b', $linked->respondingToRequestId);

        $refused = AbdmHipMessages::readCallback('care-contexts-linked', self::HEADERS, (string) json_encode([
            'response' => ['requestId' => 'ours'],
            'error' => ['code' => 'ABDM-1092', 'message' => 'Link token expired'],
        ]));
        $this->assertInstanceOf(CareContextsLinked::class, $refused);
        $this->assertSame('ABDM-1092', $refused->error?->code);
    }

    public function test_discovery_trusts_only_verified_identifiers(): void
    {
        $discovery = $this->read('discover', 'discover');

        $this->assertInstanceOf(DiscoveryRequested::class, $discovery);
        $this->assertSame(
            ['a8b3c1d2-0000-4c5d-8e9f-111122223333', 'IN1010000101', 'Asha Kumari', 'F', 1990, '9876501234', '91-1111-2222-3333', 'UH000000001'],
            [$discovery->transactionId, $discovery->hipId, $discovery->name, $discovery->gender, $discovery->yearOfBirth, $discovery->verifiedMobile, $discovery->verifiedAbhaNumber, $discovery->unverifiedUhid],
        );
    }

    public function test_linking_reads_the_chosen_records_and_the_code(): void
    {
        $init = $this->read('link-init', 'link-init');
        $this->assertInstanceOf(LinkRequested::class, $init);
        $this->assertSame(['UH000000001', ['01920000-0000-7000-8000-000000000001']], [$init->patientReference, $init->careContextReferences]);

        $confirm = $this->read('link-confirm', 'link-confirm');
        $this->assertInstanceOf(LinkConfirmed::class, $confirm);
        $this->assertSame(['4cf5d1b7a0e14c3c9f2b6e8d7a6b5c4d', '123456'], [$confirm->linkReference, $confirm->code]);
    }

    public function test_a_granted_consent_carries_its_artefact_and_a_revocation_only_its_id(): void
    {
        $granted = $this->read('consent-notify', 'consent-notify-granted');
        $this->assertInstanceOf(ConsentNotified::class, $granted);
        $artefact = $granted->artefact;
        $this->assertNotNull($artefact);
        $this->assertSame(
            ['c0ns3nt-0000-4000-8000-000000000001', 'GRANTED', 'Dr. Meera Sinha', 'CAREMGT', ['01920000-0000-7000-8000-000000000001'], ['DiagnosticReport'], 'VIEW'],
            [$granted->consentArtefactId, $granted->status, $artefact->requesterName, $artefact->purposeCode, $artefact->careContextReferences, $artefact->hiTypes, $artefact->accessMode],
        );
        $this->assertSame('2027-01-31T00:00:00Z', $artefact->dataEraseAt?->toIso8601ZuluString());

        $revoked = $this->read('consent-notify', 'consent-notify-revoked');
        $this->assertInstanceOf(ConsentNotified::class, $revoked);
        $this->assertSame(['REVOKED', null], [$revoked->status, $revoked->artefact]);
    }

    public function test_a_data_request_carries_the_receivers_key(): void
    {
        $request = $this->read('health-information-request', 'health-information-request');

        $this->assertInstanceOf(HealthInformationRequested::class, $request);
        $this->assertSame(
            ['7e1d0c3b-2a19-4f08-b7e6-d5c4b3a29180', 'c0ns3nt-0000-4000-8000-000000000001', 'https://hiu.example.in/data/push', 'ECDH', 'Curve25519', '3q2+7wABAgMEBQYHCAkKCwwNDg8QERITFBUWFxgZGhs='],
            [$request->transactionId, $request->consentArtefactId, $request->dataPushUrl, $request->keyMaterial->cryptoAlgorithm, $request->keyMaterial->curve, $request->keyMaterial->publicKey],
        );
        $this->assertSame('2026-12-31T23:59:59Z', $request->dateTo->toIso8601ZuluString());
    }

    public function test_missing_or_badly_typed_fields_are_reported_not_crashed_on(): void
    {
        $this->assertMalformed('discover', ['transactionId' => 'x', 'patient' => ['gender' => 'F']], 'patient.name');
        $this->assertMalformed('consent-notify', ['notification' => ['status' => 'GRANTED', 'consentId' => 'c']], 'notification.consentDetail');
        $this->assertMalformed('health-information-request', ['transactionId' => 't', 'hiRequest' => ['consent' => ['id' => 'c'], 'dateRange' => ['from' => 'not a date', 'to' => 'x']]], 'hiRequest.dateRange.from');
        // Without the header, the request ID must be in the body.
        $this->assertMalformed('link-confirm', ['confirmation' => ['linkRefNumber' => 'r', 'token' => '1']], 'requestId', headers: ['x-hip-id' => 'IN1']);
    }

    public function test_our_answers_are_written_in_abdms_shape(): void
    {
        $discovery = $this->read('discover', 'discover');
        $this->assertInstanceOf(DiscoveryRequested::class, $discovery);
        $found = new PatientCareContexts('UH000000001', 'Asha Kumari', [new CareContext('r-1', 'Lab report, 12 Oct 2026, Patna Clinical Lab')]);

        $this->assertSame([
            'transactionId' => 'a8b3c1d2-0000-4c5d-8e9f-111122223333',
            'patient' => [[
                'referenceNumber' => 'UH000000001',
                'display' => 'Asha Kumari',
                'careContexts' => [['referenceNumber' => 'r-1', 'display' => 'Lab report, 12 Oct 2026, Patna Clinical Lab']],
                'hiType' => 'DiagnosticReport',
                'count' => 1,
            ]],
            'matchedBy' => ['MOBILE', 'MR'],
            'response' => ['requestId' => 'req-1'],
        ], AbdmHipMessages::onDiscover($discovery, $found, ['mobile', 'uhid'], null));

        $this->assertSame([
            'transactionId' => 'a8b3c1d2-0000-4c5d-8e9f-111122223333',
            'error' => ['code' => 'PATIENT_NOT_FOUND', 'message' => 'No match.'],
            'response' => ['requestId' => 'req-1'],
        ], AbdmHipMessages::onDiscover($discovery, null, [], new HipError('PATIENT_NOT_FOUND', 'No match.')));

        $init = $this->read('link-init', 'link-init');
        $this->assertInstanceOf(LinkRequested::class, $init);
        $challenge = AbdmHipMessages::onLinkInit($init, new LinkChallenge('ref-1', '******1234', CarbonImmutable::parse('2026-10-12T05:10:00Z')), null);
        $this->assertSame(['referenceNumber' => 'ref-1', 'authenticationType' => 'DIRECT', 'meta' => [
            'communicationMedium' => 'MOBILE', 'communicationHint' => '******1234', 'communicationExpiry' => '2026-10-12T05:10:00.000Z',
        ]], $challenge['link']);

        $this->assertSame(
            ['REQUEST-ID' => 'req-9', 'TIMESTAMP' => '2026-10-12T05:00:00.000Z', 'X-HIP-ID' => 'IN1010000101', 'X-CM-ID' => 'sbx'],
            AbdmHipMessages::headers('req-9', 'IN1010000101', CarbonImmutable::parse('2026-10-12T05:00:00Z'), 'sbx'),
        );
    }

    private function read(string $type, string $fixture): object
    {
        return AbdmHipMessages::readCallback($type, self::HEADERS, (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/Abdm/{$fixture}.json"));
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function assertMalformed(string $type, array $body, ?string $field, array $headers = self::HEADERS): void
    {
        try {
            AbdmHipMessages::readCallback($type, $headers, (string) json_encode($body));
            $this->fail("A malformed {$type} callback was accepted.");
        } catch (AbdmHipError $error) {
            $this->assertSame(['ABDM_CALLBACK_MALFORMED', 400], [$error->errorCode, $error->httpStatus]);

            if ($field !== null) {
                $this->assertSame($field, $error->details[0]['field'] ?? null);
            }
        }
    }
}
