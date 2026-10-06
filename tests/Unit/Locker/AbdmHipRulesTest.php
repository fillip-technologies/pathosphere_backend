<?php

namespace Tests\Unit\Locker;

use App\Modules\Booking\Services\AbhaDiscoveryCandidate;
use App\Modules\Lab\Services\ExchangeSigner;
use App\Modules\Lab\Services\ExchangeTest;
use App\Modules\Lab\Services\ReleasedResult;
use App\Modules\Lab\Services\ReportForExchange;
use App\Modules\Locker\Contracts\Abdm\DiscoveryRequested;
use App\Modules\Locker\Domain\BundleSubject;
use App\Modules\Locker\Domain\DiagnosticReportRecord;
use App\Modules\Locker\Domain\DiscoveryMatch;
use App\Modules\Locker\Domain\DiscoveryMatcher;
use App\Modules\Locker\Domain\FideliusCipher;
use App\Modules\Network\Services\BranchLetterhead;
use App\Modules\Shared\Enums\Gender;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** The pure rules of ABDM M2: encryption, discovery matching and the FHIR record. */
final class AbdmHipRulesTest extends TestCase
{
    public function test_both_sides_derive_the_same_key_and_only_the_receiver_can_read(): void
    {
        $hiu = FideliusCipher::keyPair();
        $hip = FideliusCipher::keyPair();
        $hiuNonce = FideliusCipher::nonce();
        $hipNonce = FideliusCipher::nonce();
        $document = '{"resourceType":"Bundle","note":"Hb 11.2 g/dL — low"}';

        $encrypted = FideliusCipher::encrypt($document, $hip['private'], $hiu['public'], $hipNonce, $hiuNonce);

        $this->assertStringNotContainsString('Bundle', (string) base64_decode($encrypted));
        $this->assertSame($document, FideliusCipher::decrypt($encrypted, $hiu['private'], $hip['public'], $hiuNonce, $hipNonce));

        // Anyone else's key fails, and so does a tampered ciphertext.
        $stranger = FideliusCipher::keyPair();
        $this->expectException(InvalidArgumentException::class);
        FideliusCipher::decrypt($encrypted, $stranger['private'], $hip['public'], $hiuNonce, $hipNonce);
    }

    public function test_a_tampered_ciphertext_is_rejected(): void
    {
        $hiu = FideliusCipher::keyPair();
        $hip = FideliusCipher::keyPair();
        [$a, $b] = [FideliusCipher::nonce(), FideliusCipher::nonce()];
        $bytes = (string) base64_decode(FideliusCipher::encrypt('result', $hip['private'], $hiu['public'], $a, $b));
        $bytes[0] = chr(ord($bytes[0]) ^ 1);

        $this->expectException(InvalidArgumentException::class);
        FideliusCipher::decrypt(base64_encode($bytes), $hiu['private'], $hip['public'], $b, $a);
    }

    public function test_only_32_byte_keys_and_nonces_are_accepted(): void
    {
        $key = FideliusCipher::keyPair()['public'];

        $this->assertTrue(FideliusCipher::accepts($key, FideliusCipher::nonce()));
        $this->assertFalse(FideliusCipher::accepts(base64_encode('short'), FideliusCipher::nonce()));
        $this->assertFalse(FideliusCipher::accepts($key, base64_encode(random_bytes(16))));
        $this->assertFalse(FideliusCipher::accepts('not base64!', FideliusCipher::nonce()));
    }

    public function test_the_abha_number_linked_at_our_desk_identifies_the_patient(): void
    {
        $match = DiscoveryMatcher::match($this->search(abhaNumber: '91-1111-2222-3333', mobile: null), [
            $this->candidate('asha', abhaMatches: true, phoneMatches: false, name: 'A. Kumari'),
        ]);

        $this->assertSame(['asha', ['abha_number']], [$match->patient?->patientId, $match->matchedBy]);
    }

    public function test_a_verified_mobile_needs_the_same_name_gender_and_year_of_birth(): void
    {
        $asha = $this->candidate('asha');

        $this->assertSame('asha', DiscoveryMatcher::match($this->search(), [$asha])->patient?->patientId);
        // Titles, dots, case and a middle name do not matter; a year either way is allowed.
        $this->assertSame('asha', DiscoveryMatcher::match($this->search(name: 'smt. ASHA devi kumari', yearOfBirth: 1991), [$asha])->patient?->patientId);

        $this->assertSame(DiscoveryMatch::NOT_FOUND, DiscoveryMatcher::match($this->search(name: 'Usha Kumari'), [$asha])->errorCode);
        $this->assertSame(DiscoveryMatch::NOT_FOUND, DiscoveryMatcher::match($this->search(gender: 'M'), [$asha])->errorCode);
        $this->assertSame(DiscoveryMatch::NOT_FOUND, DiscoveryMatcher::match($this->search(yearOfBirth: 1985), [$asha])->errorCode);
        // Kumari is a surname here, not a title.
        $this->assertSame(DiscoveryMatch::NOT_FOUND, DiscoveryMatcher::match($this->search(name: 'Asha'), [$asha])->errorCode);
        // Nothing verified: nothing found.
        $this->assertSame(DiscoveryMatch::NOT_FOUND, DiscoveryMatcher::match($this->search(mobile: null), [$asha])->errorCode);
    }

    public function test_two_matching_patients_on_one_phone_need_a_uhid_to_choose(): void
    {
        $candidates = [$this->candidate('asha-1', uhid: 'UH000000001'), $this->candidate('asha-2', uhid: 'UH000000007')];

        $this->assertSame(DiscoveryMatch::AMBIGUOUS, DiscoveryMatcher::match($this->search(), $candidates)->errorCode);

        $chosen = DiscoveryMatcher::match($this->search(uhid: 'uh000000007'), $candidates);
        $this->assertSame(['asha-2', ['mobile', 'uhid']], [$chosen->patient?->patientId, $chosen->matchedBy]);

        $this->assertSame(DiscoveryMatch::AMBIGUOUS, DiscoveryMatcher::match($this->search(uhid: 'UH999'), $candidates)->errorCode);
    }

    public function test_a_report_becomes_a_fhir_document_with_coded_results_and_the_signed_pdf(): void
    {
        $bundle = DiagnosticReportRecord::bundle($this->report(), $this->subject(), [
            new ReleasedResult('CBC', 'Complete Blood Count', 'HB', 'Haemoglobin', '11.2', '11.2000', 'g/dL', '12 - 15', 'low'),
            new ReleasedResult('CBC', 'Complete Blood Count', 'PLT', 'Platelet Count', '<20', '20.0000', '10^3/µL', '150 - 410', 'critical_low'),
            new ReleasedResult('CBC', 'Complete Blood Count', 'NOTE', 'Remarks', 'Smear advised', null, null, null, null),
        ], '%PDF-1.4 report', CarbonImmutable::parse('2026-10-12T06:00:00Z'), 'https://lab.example/fhir');

        $this->assertSame(['Bundle', 'document'], [$bundle['resourceType'], $bundle['type']]);
        $resources = array_column(array_column($bundle['entry'], 'resource'), null, 'id');
        $types = array_map(fn (array $resource) => $resource['resourceType'], array_values($resources));
        $this->assertSame('Composition', $types[0]);
        $this->assertSame(['Composition', 'Patient', 'Organization', 'Practitioner', 'Observation', 'Observation', 'Observation', 'DiagnosticReport', 'DocumentReference'], $types);

        $byType = fn (string $type) => array_values(array_filter($resources, fn (array $resource) => $resource['resourceType'] === $type));
        [$haemoglobin, $platelets, $remark] = $byType('Observation');
        $this->assertSame(['http://loinc.org', '718-7'], [$haemoglobin['code']['coding'][0]['system'], $haemoglobin['code']['coding'][0]['code']]);
        $this->assertSame(['value' => 11.2, 'unit' => 'g/dL'], $haemoglobin['valueQuantity']);
        $this->assertSame('L', $haemoglobin['interpretation'][0]['coding'][0]['code']);
        $this->assertSame([['text' => '12 - 15']], $haemoglobin['referenceRange']);
        // An analyser's "<20" keeps its comparator; a parameter without LOINC keeps our code.
        $this->assertSame(['value' => 20, 'comparator' => '<', 'unit' => '10^3/µL'], $platelets['valueQuantity']);
        $this->assertSame('LL', $platelets['interpretation'][0]['coding'][0]['code']);
        $this->assertSame(['https://lab.example/fhir/parameters', 'CBC.PLT'], [$platelets['code']['coding'][0]['system'], $platelets['code']['coding'][0]['code']]);
        $this->assertSame('Smear advised', $remark['valueString']);
        $this->assertArrayNotHasKey('interpretation', $remark);

        $report = $byType('DiagnosticReport')[0];
        $this->assertSame(['final', '58410-2', 'Haematology'], [$report['status'], $report['code']['coding'][0]['code'], $report['category'][0]['text']]);
        $this->assertCount(3, $report['result']);
        $this->assertSame('urn:uuid:'.$byType('Practitioner')[0]['id'], $report['resultsInterpreter'][0]['reference']);
        $this->assertSame(['https://doctor.ndhm.gov.in', '71-4433-2211-0099'], [$byType('Practitioner')[0]['identifier'][0]['system'], $byType('Practitioner')[0]['identifier'][0]['value']]);
        $this->assertSame(['https://facility.ndhm.gov.in', 'IN1010000101'], [$byType('Organization')[0]['identifier'][0]['system'], $byType('Organization')[0]['identifier'][0]['value']]);
        $this->assertSame(['UH000000001', '91-1111-2222-3333'], array_column($byType('Patient')[0]['identifier'], 'value'));
        $this->assertSame(['female', '1990-04-02'], [$byType('Patient')[0]['gender'], $byType('Patient')[0]['birthDate']]);
        $this->assertSame('%PDF-1.4 report', base64_decode($byType('DocumentReference')[0]['content'][0]['attachment']['data']));

        // Every reference in the document points at a resource in it.
        preg_match_all('/"reference":"urn:uuid:([0-9a-f-]+)"/', (string) json_encode($bundle), $references);
        $this->assertSame([], array_diff(array_unique($references[1]), array_keys($resources)));
    }

    public function test_the_same_report_always_gives_the_same_document_and_a_correction_is_amended(): void
    {
        $at = CarbonImmutable::parse('2026-10-12T06:00:00Z');
        $first = DiagnosticReportRecord::bundle($this->report(), $this->subject(), [], null, $at, 'https://lab.example/fhir');

        $this->assertSame($first, DiagnosticReportRecord::bundle($this->report(), $this->subject(), [], null, $at, 'https://lab.example/fhir'));
        $this->assertNotContains('DocumentReference', array_map(fn (array $entry) => $entry['resource']['resourceType'], $first['entry']));

        $corrected = DiagnosticReportRecord::bundle($this->report(version: 2), $this->subject(), [], null, $at, 'https://lab.example/fhir');
        $statuses = array_column(array_filter(array_column($corrected['entry'], 'resource'), fn (array $resource) => $resource['resourceType'] === 'DiagnosticReport'), 'status');
        $this->assertSame(['amended'], $statuses);
        $this->assertSame('2', $corrected['meta']['versionId']);
    }

    private function search(?string $abhaNumber = null, ?string $mobile = '9876501234', string $name = 'Asha Kumari', string $gender = 'F', ?int $yearOfBirth = 1990, ?string $uhid = null): DiscoveryRequested
    {
        return new DiscoveryRequested('req', 'txn', 'IN1010000101', 'asha@sbx', $name, $gender, $yearOfBirth, $mobile, $abhaNumber, $uhid);
    }

    private function candidate(string $id, bool $abhaMatches = false, bool $phoneMatches = true, string $name = 'Asha Kumari', string $uhid = 'UH000000001'): AbhaDiscoveryCandidate
    {
        return new AbhaDiscoveryCandidate($id, 'org', $uhid, $name, Gender::Female, 1990, $abhaMatches, $phoneMatches);
    }

    private function report(int $version = 1): ReportForExchange
    {
        return new ReportForExchange(
            '01920000-0000-7000-8000-000000000001',
            'org',
            'patient',
            $version,
            true,
            'ORD/PATPSC1/000123',
            CarbonImmutable::parse('2026-10-12T04:30:00Z'),
            CarbonImmutable::parse('2026-10-12T05:45:00Z'),
            new BranchLetterhead('lab', 'PATCL1', 'Patna Clinical Lab', 'Boring Road, Patna', '0612-2222222', 'MC-1234', null, 'CE-99', 'IN1010000101'),
            [new ExchangeTest('CBC', 'Complete Blood Count', '58410-2', 'dept-haem', 'Haematology', ['HB' => '718-7'])],
            [new ExchangeSigner('signatory-1', 'dept-haem', 'Dr Rakesh Verma', 'MD Pathology', 'Bihar Medical Council', 'BMC-12345', '71-4433-2211-0099', CarbonImmutable::parse('2026-10-12T05:40:00Z'))],
            true,
        );
    }

    private function subject(): BundleSubject
    {
        return new BundleSubject('UH000000001', 'Asha Kumari', Gender::Female, CarbonImmutable::parse('1990-04-02'), '91-1111-2222-3333', 'asha@sbx');
    }
}
