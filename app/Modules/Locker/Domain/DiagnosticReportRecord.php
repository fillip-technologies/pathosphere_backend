<?php

namespace App\Modules\Locker\Domain;

use App\Modules\Lab\Services\ExchangeTest;
use App\Modules\Lab\Services\ReleasedResult;
use App\Modules\Lab\Services\ReportForExchange;
use App\Modules\Shared\Enums\Gender;
use Carbon\CarbonImmutable;

/**
 * One released report as a FHIR R4 document bundle in India's NRCeS
 * "DiagnosticReportRecord" profile, the form ABDM shares lab reports in
 * (spec §5.7 M2):
 *
 *   Composition → one DiagnosticReport per test (coded by the test's LOINC
 *   code), each with an Observation per result (LOINC per parameter where
 *   known), and a DocumentReference holding the signed PDF exactly as
 *   released. The lab is the Organization (HFR ID), each signing doctor a
 *   Practitioner (HPR ID).
 *
 * Pure: resource IDs are derived from the report, so the same report always
 * gives the same bundle. Validate against the NRCeS profiles during sandbox
 * certification.
 */
final class DiagnosticReportRecord
{
    private const PROFILE = 'https://nrces.in/ndhm/fhir/r4/StructureDefinition/';

    private const LOINC = 'http://loinc.org';

    private const SNOMED = 'http://snomed.info/sct';

    private const IDENTIFIER_TYPE = 'http://terminology.hl7.org/CodeSystem/v2-0203';

    private const INTERPRETATION = 'http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation';

    private const FACILITY_REGISTRY = 'https://facility.ndhm.gov.in';

    private const PROFESSIONAL_REGISTRY = 'https://doctor.ndhm.gov.in';

    private const ABHA_SYSTEM = 'https://healthid.ndhm.gov.in';

    /**
     * @param  list<ReleasedResult>  $results  the values as released in this version
     * @param  string  $localSystem  our code system URL, e.g. https://lab.example/fhir
     * @return array<string, mixed>
     */
    public static function bundle(ReportForExchange $report, BundleSubject $patient, array $results, ?string $pdf, CarbonImmutable $now, string $localSystem): array
    {
        $id = fn (string $key): string => self::uuid($report->reportId, $key);
        $reference = fn (string $key): array => ['reference' => 'urn:uuid:'.$id($key)];
        $issued = self::timestamp($report->releasedAt);
        $status = $report->version > 1 ? 'amended' : 'final';
        $entries = [];

        $entries[] = self::patient($id('patient'), $patient, $localSystem);
        $entries[] = self::organization($id('lab'), $report);

        $practitionerKeys = [];
        foreach ($report->signers as $signer) {
            $key = 'practitioner:'.$signer->signatoryId;

            if (! isset($practitionerKeys[$key])) {
                $practitionerKeys[$key] = true;
                $entries[] = self::resource($id($key), [
                    'resourceType' => 'Practitioner',
                    'meta' => ['profile' => [self::PROFILE.'Practitioner']],
                    'identifier' => array_values(array_filter([
                        $signer->hprId === null ? null : [
                            'type' => self::identifierType('MD', 'Medical License number'),
                            'system' => self::PROFESSIONAL_REGISTRY,
                            'value' => $signer->hprId,
                        ],
                        [
                            'type' => self::identifierType('MD', 'Medical License number'),
                            'system' => $localSystem.'/council/'.rawurlencode($signer->councilName),
                            'value' => $signer->registrationNo,
                        ],
                    ])),
                    'name' => [['text' => $signer->name]],
                    'qualification' => [['code' => ['text' => $signer->qualification]]],
                ]);
            }
        }

        $byTest = [];
        foreach ($results as $result) {
            $byTest[$result->testCode][] = $result;
        }

        $sectionEntries = [];
        foreach ($report->tests as $test) {
            $observationRefs = [];

            foreach ($byTest[$test->code] ?? [] as $result) {
                $key = "observation:{$test->code}:{$result->parameterCode}";
                $observationRefs[] = $reference($key);
                $entries[] = self::observation($id($key), $test, $result, $status, $issued, $reference('patient'), $reference('lab'), $localSystem);
            }

            $signer = $report->signerFor($test->departmentId);
            $key = 'diagnostic-report:'.$test->code;
            $sectionEntries[] = $reference($key);
            $entries[] = self::resource($id($key), array_filter([
                'resourceType' => 'DiagnosticReport',
                'meta' => ['profile' => [self::PROFILE.'DiagnosticReportLab']],
                'identifier' => [['system' => $localSystem.'/reports', 'value' => "{$report->orderNo}/{$test->code}/v{$report->version}"]],
                'status' => $status,
                'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v2-0074', 'code' => 'LAB', 'display' => 'Laboratory']], 'text' => $test->departmentName]],
                'code' => self::code($test->loincCode, $test->name, $localSystem.'/tests', $test->code),
                'subject' => $reference('patient'),
                'effectiveDateTime' => self::timestamp($report->orderDate),
                'issued' => $issued,
                'performer' => [$reference('lab')],
                'resultsInterpreter' => $signer === null ? null : [$reference('practitioner:'.$signer->signatoryId)],
                'result' => $observationRefs === [] ? null : $observationRefs,
            ], fn ($value) => $value !== null));
        }

        if ($pdf !== null) {
            $sectionEntries[] = $reference('document');
            $entries[] = self::resource($id('document'), [
                'resourceType' => 'DocumentReference',
                'meta' => ['profile' => [self::PROFILE.'DocumentReference']],
                'status' => 'current',
                'docStatus' => $status === 'final' ? 'final' : 'amended',
                'type' => ['coding' => [['system' => self::SNOMED, 'code' => '4241000179101', 'display' => 'Laboratory report']], 'text' => 'Laboratory report'],
                'subject' => $reference('patient'),
                'date' => $issued,
                'content' => [[
                    'attachment' => [
                        'contentType' => 'application/pdf',
                        'language' => 'en-IN',
                        'data' => base64_encode($pdf),
                        'title' => "Lab report {$report->orderNo} (version {$report->version})",
                        'creation' => $issued,
                    ],
                ]],
            ]);
        }

        $composition = self::resource($id('composition'), [
            'resourceType' => 'Composition',
            'meta' => ['profile' => [self::PROFILE.'DiagnosticReportRecord']],
            'identifier' => ['system' => $localSystem.'/documents', 'value' => $report->reportId],
            'status' => 'final',
            'type' => ['coding' => [['system' => self::SNOMED, 'code' => '721981007', 'display' => 'Diagnostic studies report']], 'text' => 'Diagnostic Report- Lab'],
            'subject' => $reference('patient'),
            'date' => $issued,
            'author' => [$reference('lab')],
            'title' => 'Diagnostic Report- Lab',
            'custodian' => $reference('lab'),
            'section' => [[
                'title' => $report->lab->name,
                'code' => ['coding' => [['system' => self::SNOMED, 'code' => '721981007', 'display' => 'Diagnostic studies report']]],
                'entry' => $sectionEntries,
            ]],
        ]);

        return [
            'resourceType' => 'Bundle',
            'id' => $id('bundle'),
            'meta' => [
                'versionId' => (string) $report->version,
                'lastUpdated' => self::timestamp($now),
                'profile' => [self::PROFILE.'DocumentBundle'],
                'security' => [['system' => 'http://terminology.hl7.org/CodeSystem/v3-Confidentiality', 'code' => 'V', 'display' => 'very restricted']],
            ],
            'identifier' => ['system' => $localSystem.'/bundles', 'value' => $id('bundle')],
            'type' => 'document',
            'timestamp' => self::timestamp($now),
            'entry' => [$composition, ...$entries],
        ];
    }

    /**
     * @param  array{reference: string}  $subject
     * @param  array{reference: string}  $performer
     * @return array<string, mixed>
     */
    private static function observation(string $id, ExchangeTest $test, ReleasedResult $result, string $status, string $issued, array $subject, array $performer, string $localSystem): array
    {
        return self::resource($id, array_filter([
            'resourceType' => 'Observation',
            'meta' => ['profile' => [self::PROFILE.'Observation']],
            'status' => $status,
            'code' => self::code($test->parameterLoincCodes[$result->parameterCode] ?? null, $result->parameterName, $localSystem.'/parameters', "{$test->code}.{$result->parameterCode}"),
            'subject' => $subject,
            'issued' => $issued,
            'performer' => [$performer],
            ...self::value($result),
            'interpretation' => self::interpretation($result->flag),
            'referenceRange' => $result->referenceRange === null ? null : [['text' => $result->referenceRange]],
        ], fn ($value) => $value !== null));
    }

    /** @return array<string, mixed> */
    private static function value(ReleasedResult $result): array
    {
        if ($result->valueNumeric === null) {
            return $result->value === null ? [] : ['valueString' => $result->value];
        }

        // Analysers may report "<0.5": the number travels with its comparator.
        preg_match('/^\s*(<=|>=|<|>)/', (string) $result->value, $comparator);
        $trimmed = rtrim(rtrim($result->valueNumeric, '0'), '.');
        $quantity = array_filter([
            'value' => str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed,
            'comparator' => $comparator[1] ?? null,
            'unit' => $result->unit,
        ], fn ($value) => $value !== null);

        return ['valueQuantity' => $quantity];
    }

    /** @return list<array<string, mixed>>|null */
    private static function interpretation(?string $flag): ?array
    {
        $code = match ($flag) {
            'normal' => ['N', 'Normal'],
            'low' => ['L', 'Low'],
            'high' => ['H', 'High'],
            'critical_low' => ['LL', 'Critical low'],
            'critical_high' => ['HH', 'Critical high'],
            'abnormal' => ['A', 'Abnormal'],
            default => null,
        };

        return $code === null ? null : [['coding' => [['system' => self::INTERPRETATION, 'code' => $code[0], 'display' => $code[1]]], 'text' => $code[1]]];
    }

    /** @return array<string, mixed> */
    private static function patient(string $id, BundleSubject $patient, string $localSystem): array
    {
        return self::resource($id, array_filter([
            'resourceType' => 'Patient',
            'meta' => ['profile' => [self::PROFILE.'Patient']],
            'identifier' => array_filter([
                ['type' => self::identifierType('MR', 'Medical record number'), 'system' => $localSystem.'/uhid', 'value' => $patient->uhid],
                $patient->abhaNumber === null ? null : [
                    'type' => ['coding' => [['system' => 'https://nrces.in/ndhm/fhir/r4/CodeSystem/ndhm-identifier-type-code', 'code' => 'ABHA', 'display' => 'Ayushman Bharat Health Account (ABHA) ID']]],
                    'system' => self::ABHA_SYSTEM,
                    'value' => $patient->abhaNumber,
                ],
            ]),
            'name' => [['text' => $patient->name]],
            'gender' => match ($patient->gender) {
                Gender::Male => 'male',
                Gender::Female => 'female',
                Gender::Other => 'other',
                Gender::Unknown => 'unknown',
            },
            'birthDate' => $patient->dob?->toDateString(),
        ], fn ($value) => $value !== null));
    }

    /** @return array<string, mixed> */
    private static function organization(string $id, ReportForExchange $report): array
    {
        $lab = $report->lab;

        return self::resource($id, array_filter([
            'resourceType' => 'Organization',
            'meta' => ['profile' => [self::PROFILE.'Organization']],
            'identifier' => $lab->hfrId === null ? null : [[
                'type' => self::identifierType('PRN', 'Provider number'),
                'system' => self::FACILITY_REGISTRY,
                'value' => $lab->hfrId,
            ]],
            'name' => $lab->name,
            'telecom' => [['system' => 'phone', 'value' => $lab->phone, 'use' => 'work']],
            'address' => [['text' => $lab->address]],
        ], fn ($value) => $value !== null));
    }

    /** @return array<string, mixed> */
    private static function code(?string $loincCode, string $name, string $localSystem, string $localCode): array
    {
        return [
            'coding' => [$loincCode === null
                ? ['system' => $localSystem, 'code' => $localCode, 'display' => $name]
                : ['system' => self::LOINC, 'code' => $loincCode, 'display' => $name]],
            'text' => $name,
        ];
    }

    /** @return array<string, mixed> */
    private static function identifierType(string $code, string $display): array
    {
        return ['coding' => [['system' => self::IDENTIFIER_TYPE, 'code' => $code, 'display' => $display]]];
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    private static function resource(string $id, array $resource): array
    {
        return ['fullUrl' => 'urn:uuid:'.$id, 'resource' => ['id' => $id, ...$resource]];
    }

    /** A UUID derived from the report and the resource's role in it. */
    private static function uuid(string $reportId, string $key): string
    {
        $hash = hash('sha256', "{$reportId}|{$key}");

        return sprintf(
            '%s-%s-5%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec($hash[16]) & 0x3) | 0x8),
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }

    private static function timestamp(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
