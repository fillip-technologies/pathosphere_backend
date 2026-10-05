<?php

namespace Tests\Feature\Booking;

use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Booking\Infrastructure\FakeAbdmClient;
use App\Modules\Booking\Models\AbdmRequest;
use App\Modules\Booking\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\TestCase;

/** Patient registration, search, merge and ABHA M1 (spec §5.2 step 1, §5.7, §7.3). */
final class PatientAndAbhaTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use RefreshDatabase;

    private const ABHA_NUMBER = '91-5555-6666-7777';

    private const CALLBACK_SECRET = 'abdm-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.abdm.callback_secret' => self::CALLBACK_SECRET]);
        $this->setUpDemoNetwork();
        $this->actingAsStaff($this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATPSC1')]));
    }

    public function test_patients_get_sequential_uhids_and_are_found_by_phone_or_uhid_from_any_branch(): void
    {
        $first = $this->registerPatient();
        $second = $this->registerPatient(['name' => 'Ravi Kumar', 'gender' => 'male', 'phone' => '9876501234']);
        $this->assertSame((int) substr($first['uhid'], 2) + 1, (int) substr($second['uhid'], 2));

        // A front desk at a franchise branch in another region finds them too.
        $this->actingAsStaff($this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('DHNPSC1')]));

        $this->getJson('/api/v1/patients?q=9876501234')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.phone_masked', '******1234')
            ->assertJsonMissingPath('data.0.phone');

        $this->getJson("/api/v1/patients?q={$second['uhid']}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ravi Kumar');
        $this->getJson('/api/v1/patients')->assertStatus(422)->assertJsonPath('error.details.0.field', 'q');
    }

    public function test_a_merged_record_points_to_the_survivor_and_cannot_be_booked(): void
    {
        $duplicate = $this->registerPatient();
        $survivor = $this->registerPatient();

        $this->postJson("/api/v1/patients/{$duplicate['id']}/merge", ['merge_into_id' => $survivor['id']])->assertOk()->assertJsonPath('data.id', $survivor['id']);

        $this->getJson("/api/v1/patients/{$duplicate['id']}")->assertOk()->assertJsonPath('data.id', $survivor['id']);
        $this->getJson("/api/v1/patients?q={$duplicate['uhid']}")->assertJsonCount(0, 'data');

        $this->bookOrder($duplicate['id'], 'PATPSC1', ['items' => [$this->testItem('CBC')]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PATIENT_MERGED')
            ->assertJsonPath('error.details.0.merged_into_id', $survivor['id']);
    }

    public function test_verifying_an_existing_abha_links_it_and_reports_differences_without_overwriting(): void
    {
        $patient = $this->registerPatient(['name' => 'Asha Devi', 'age_years' => 30]);

        $txnId = $this->postJson('/api/v1/abha-verifications', ['patient_id' => $patient['id'], 'abha_number' => self::ABHA_NUMBER])
            ->assertOk()->json('data.txn_id');

        $this->postJson("/api/v1/abha-verifications/{$txnId}/confirmation", ['otp' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ABDM_OTP_INVALID');

        $response = $this->postJson("/api/v1/abha-verifications/{$txnId}/confirmation", ['otp' => FakeAbdmClient::VALID_OTP])->assertOk();

        $response->assertJsonPath('data.patient.abha.status', 'linked')
            ->assertJsonPath('data.patient.abha.number', self::ABHA_NUMBER)
            ->assertJsonPath('data.patient.name', 'Asha Devi')
            ->assertJsonPath('data.mismatches.0.field', 'name')
            ->assertJsonPath('data.mismatches.0.abha_value', 'Asha Kumari');

        // Stored encrypted, found by its blind index.
        $raw = DB::table('patients')->where('id', $patient['id'])->value('abha_number');
        $this->assertStringNotContainsString('555566667777', (string) $raw);
        $this->getJson('/api/v1/patients?q=91555566667777')->assertJsonPath('data.0.id', $patient['id']);

        // Every ABDM call logged: the failed OTP too, and never the OTP itself.
        $logs = $this->asSystem(fn () => AbdmRequest::query()->where('patient_id', $patient['id'])->orderBy('id')->get());
        $this->assertSame(['abha_otp_request', 'abha_otp_verify', 'abha_otp_verify'], $logs->pluck('api_name')->all());
        $this->assertSame(['success', 'failed', 'success'], $logs->pluck('status')->map->value->all());
        $this->assertStringNotContainsString('123456', $logs->toJson());
    }

    public function test_one_abha_cannot_be_linked_to_two_patients(): void
    {
        $first = $this->registerPatient();
        $second = $this->registerPatient(['name' => 'Other Person']);
        $this->linkAbha($first['id']);

        $txnId = $this->postJson('/api/v1/abha-verifications', ['patient_id' => $second['id'], 'abha_number' => self::ABHA_NUMBER])->json('data.txn_id');

        $this->postJson("/api/v1/abha-verifications/{$txnId}/confirmation", ['otp' => FakeAbdmClient::VALID_OTP])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ABHA_ALREADY_LINKED');
    }

    public function test_aadhaar_enrolment_creates_an_abha_and_never_stores_the_aadhaar_number(): void
    {
        $patient = $this->registerPatient();

        $txnId = $this->postJson('/api/v1/abha-enrolments', ['patient_id' => $patient['id'], 'aadhaar_number' => '234567890123', 'consent_version' => 'ABHA-2026-1'])
            ->assertOk()->json('data.txn_id');

        $this->postJson("/api/v1/abha-enrolments/{$txnId}/confirmation", ['otp' => FakeAbdmClient::VALID_OTP, 'mobile' => '9876501234'])
            ->assertOk()
            ->assertJsonPath('data.patient.abha.kyc_verified', true)
            ->assertJsonPath('data.address_suggestions.0', 'asha.kumari');

        $this->postJson("/api/v1/abha-enrolments/{$txnId}/address", ['abha_address' => 'asha.kumari@abdm'])
            ->assertOk()
            ->assertJsonPath('data.abha.address', 'asha.kumari@abdm');

        $this->get("/api/v1/patients/{$patient['id']}/abha-card")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        foreach (['patients', 'abdm_requests', 'audit_logs'] as $table) {
            $this->assertStringNotContainsString('234567890123', DB::table($table)->get()->toJson(), "Aadhaar number found in {$table}.");
        }
        $this->assertStringContainsString('ABHA-2026-1', DB::table('abdm_requests')->where('api_name', 'enrol_request_otp')->value('payload_masked'));
    }

    public function test_unlinking_keeps_history(): void
    {
        $patient = $this->registerPatient();
        $this->linkAbha($patient['id']);

        $this->deleteJson("/api/v1/patients/{$patient['id']}/abha-link")->assertNoContent();

        $this->getJson("/api/v1/patients/{$patient['id']}")->assertJsonPath('data.abha.status', 'unlinked')->assertJsonPath('data.abha.number', null);
        $this->assertSame(2, $this->asSystem(fn () => AbdmRequest::query()->where('patient_id', $patient['id'])->count()));
    }

    public function test_scanning_an_abha_qr_starts_otp_verification(): void
    {
        $patient = $this->registerPatient();
        $qr = json_encode(['hidn' => self::ABHA_NUMBER, 'hid' => 'asha@abdm', 'name' => 'Asha Kumari']);

        $txnId = $this->postJson('/api/v1/abha-qr-scans', ['patient_id' => $patient['id'], 'qr_payload' => $qr])->assertOk()->json('data.txn_id');
        $this->postJson("/api/v1/abha-verifications/{$txnId}/confirmation", ['otp' => FakeAbdmClient::VALID_OTP])->assertOk();

        $this->postJson('/api/v1/abha-qr-scans', ['patient_id' => $patient['id'], 'qr_payload' => 'not a qr'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ABHA_QR_INVALID');
    }

    public function test_scan_and_share_profiles_queue_at_the_lab_and_link_to_a_patient(): void
    {
        $labId = $this->branchId('PATCL1');
        $this->asSystem(fn () => DB::table('branches')->where('id', $labId)->update(['hfr_id' => 'IN1010000001']));
        $body = (string) json_encode([
            'requestId' => 'req-share-1',
            'hipId' => 'IN1010000001',
            'profile' => ['name' => 'Asha Kumari', 'gender' => 'F', 'year_of_birth' => 1990, 'abha_number' => self::ABHA_NUMBER, 'abha_address' => 'asha@abdm'],
        ]);

        $this->call('POST', '/api/v1/abdm/callbacks/profile-share', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ABDM_SIGNATURE' => 'forged'], content: $body)
            ->assertUnauthorized();

        $this->call('POST', '/api/v1/abdm/callbacks/profile-share', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ABDM_SIGNATURE' => hash_hmac('sha256', $body, self::CALLBACK_SECRET),
        ], content: $body)->assertStatus(202);

        $this->actingAsStaff($this->staff(SystemRole::FrontDesk, ['branch_id' => $labId]));
        $share = $this->getJson("/api/v1/abha-profile-shares?filter[branch_id]={$labId}")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Asha Kumari')
            ->assertJsonMissingPath('data.0.abha_number')
            ->json('data.0');

        $patient = $this->registerPatient(['registered_branch_id' => $labId]);
        $this->postJson("/api/v1/abha-profile-shares/{$share['id']}/link", ['patient_id' => $patient['id']])
            ->assertOk()
            ->assertJsonPath('data.patient.abha.number', self::ABHA_NUMBER);

        $this->getJson("/api/v1/abha-profile-shares?filter[branch_id]={$labId}")->assertJsonCount(0, 'data');
    }

    private function linkAbha(string $patientId): void
    {
        $txnId = $this->postJson('/api/v1/abha-verifications', ['patient_id' => $patientId, 'abha_number' => self::ABHA_NUMBER])->json('data.txn_id');
        $this->postJson("/api/v1/abha-verifications/{$txnId}/confirmation", ['otp' => FakeAbdmClient::VALID_OTP])->assertOk();
        $this->assertSame('linked', $this->asSystem(fn () => Patient::query()->findOrFail($patientId)->abha_status->value));
    }
}
