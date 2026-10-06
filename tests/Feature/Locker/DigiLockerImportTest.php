<?php

namespace Tests\Feature\Locker;

use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Locker\Contracts\DigiLocker;
use App\Modules\Locker\Infrastructure\DisabledDigiLocker;
use App\Modules\Locker\Infrastructure\FakeDigiLocker;
use App\Modules\Locker\Models\ExternalHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Lab\RunsLab;
use Tests\Support\Locker\FillsLocker;
use Tests\Support\Samples\MovesSamples;
use Tests\TestCase;

/** DigiLocker pull into the health locker (spec §3, §12 Phase 9). */
final class DigiLockerImportTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use FillsLocker;
    use MovesSamples;
    use RefreshDatabase;
    use RunsLab;

    private const VACCINATION = 'in.gov.cowin-VACER-91234567890123';

    private const DRIVING_LICENCE = 'in.gov.transport-DRVLC-BR0120260001234';

    private FakeDigiLocker $digiLocker;

    private string $token;

    private string $daughterId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpDemoNetwork();
        $this->digiLocker = new FakeDigiLocker;
        $this->app->instance(DigiLocker::class, $this->digiLocker);

        $frontDesk = $this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1');
        $ashaId = $this->actingAsStaff($frontDesk)->registerPatient()['id'];
        $this->daughterId = $this->registerPatient(['name' => 'Riya Kumari', 'age_years' => 9, 'guardian_patient_id' => $ashaId])['id'];
        $this->token = $this->signInByPhone('9876501234');
    }

    public function test_a_patient_connects_digilocker_and_keeps_a_vaccination_certificate(): void
    {
        $session = $this->asPerson($this->token)->postJson('/api/v1/me/digilocker-sessions')
            ->assertCreated()
            ->assertHeader('Location')
            ->assertJsonPath('data.status', 'awaiting_authorization')
            ->json('data');
        parse_str((string) parse_url($session['authorization_url'], PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $sessionUrl = "/api/v1/me/digilocker-sessions/{$session['id']}";

        $this->getJson("{$sessionUrl}/documents")->assertStatus(409)->assertJsonPath('error.code', 'DIGILOCKER_NOT_AUTHORIZED');
        $this->postJson("{$sessionUrl}/authorization", ['code' => FakeDigiLocker::CODE, 'state' => 'forged'])
            ->assertStatus(422)->assertJsonPath('error.code', 'DIGILOCKER_STATE_MISMATCH');
        $this->postJson("{$sessionUrl}/authorization", ['code' => 'wrong', 'state' => $query['state']])
            ->assertStatus(422)->assertJsonPath('error.code', 'DIGILOCKER_AUTHORIZATION_FAILED');
        $this->postJson("{$sessionUrl}/authorization", ['code' => FakeDigiLocker::CODE, 'state' => $query['state']])
            ->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.authorization_url', null)
            ->assertJsonPath('data.digilocker_account_name', 'Asha Kumari')
            ->assertJsonMissingPath('data.access_token');

        $this->getJson("{$sessionUrl}/documents")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.uri', self::VACCINATION)
            ->assertJsonPath('data.0.importable', true)
            ->assertJsonPath('data.0.issued_on', '2022-01-14')
            ->assertJsonPath('data.0.imported_record_id', null)
            ->assertJsonPath('data.1.importable', false);

        $record = $this->postJson("{$sessionUrl}/imports", ['uri' => self::VACCINATION])
            ->assertCreated()
            ->assertJsonPath('data.source', 'digilocker')
            ->assertJsonPath('data.category.code', 'vaccination')
            ->assertJsonPath('data.title', 'COVID-19 Vaccination Certificate')
            ->assertJsonPath('data.record_date', '2022-01-14')
            ->assertJsonPath('data.provider_facility', 'Ministry of Health and Family Welfare')
            ->json('data');
        // Importing it again changes nothing.
        $this->postJson("{$sessionUrl}/imports", ['uri' => self::VACCINATION])->assertOk()->assertJsonPath('data.id', $record['id']);

        $file = $this->get("/api/v1/me/records/{$record['id']}/file")->assertOk()->streamedContent();
        $this->assertStringStartsWith('%PDF-1.4', $file);
        $this->getJson('/api/v1/me/records?filter[source]=digilocker')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $record['id']);
        $this->getJson("{$sessionUrl}/documents")->assertJsonPath('data.0.imported_record_id', $record['id']);
        $this->assertSame(1, ExternalHealthRecord::query()->where('external_id', self::VACCINATION)->count());

        // DigiLocker's copy is the original: no new versions.
        $this->post("/api/v1/me/records/{$record['id']}/documents", ['file' => UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'RECORD_NOT_UPLOADED');
        $this->postJson("{$sessionUrl}/imports", ['uri' => self::DRIVING_LICENCE])
            ->assertStatus(422)->assertJsonPath('error.code', 'DIGILOCKER_FILE_NOT_SUPPORTED');
        $this->postJson("{$sessionUrl}/imports", ['uri' => 'in.gov.someone-else-DOC-1'])
            ->assertNotFound()->assertJsonPath('error.code', 'DIGILOCKER_DOCUMENT_NOT_FOUND');
        $this->postJson("{$sessionUrl}/imports", ['uri' => self::VACCINATION, 'category' => 'no-such-category'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'category');
    }

    public function test_a_connection_belongs_to_one_profile_and_a_document_to_one_locker(): void
    {
        $forDaughter = $this->connect($this->daughterId);

        // The same phone, looking at the mother's profile, cannot use the daughter's connection.
        $this->asPerson($this->token)->getJson("/api/v1/me/digilocker-sessions/{$forDaughter}")
            ->assertNotFound()->assertJsonPath('error.code', 'DIGILOCKER_SESSION_NOT_FOUND');

        $this->asPerson($this->token, $this->daughterId)->postJson("/api/v1/me/digilocker-sessions/{$forDaughter}/imports", ['uri' => self::VACCINATION, 'title' => 'Riya vaccination'])
            ->assertCreated()->assertJsonPath('data.title', 'Riya vaccination');

        $forMother = $this->connect(null);
        $this->asPerson($this->token)->postJson("/api/v1/me/digilocker-sessions/{$forMother}/imports", ['uri' => self::VACCINATION])
            ->assertStatus(409)->assertJsonPath('error.code', 'DIGILOCKER_DOCUMENT_IN_ANOTHER_LOCKER');

        // Someone else, signed in on their own phone, cannot reach either connection.
        $this->actingAsStaff($this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1'))->registerPatient(['name' => 'Ravi Kumar', 'gender' => 'male', 'phone' => '9876509999']);
        $stranger = $this->signInByPhone('9876509999');
        $this->asPerson($stranger)->getJson("/api/v1/me/digilocker-sessions/{$forMother}/documents")->assertNotFound();
    }

    public function test_digilocker_being_down_is_reported(): void
    {
        $sessionId = $this->connect(null);
        $this->digiLocker->goDown();

        $this->asPerson($this->token)->getJson("/api/v1/me/digilocker-sessions/{$sessionId}/documents")
            ->assertStatus(503)->assertJsonPath('error.code', 'DIGILOCKER_UNAVAILABLE');
    }

    public function test_without_a_digilocker_account_the_feature_says_it_is_unavailable(): void
    {
        $this->app->instance(DigiLocker::class, new DisabledDigiLocker);

        $this->asPerson($this->token)->postJson('/api/v1/me/digilocker-sessions')
            ->assertStatus(503)->assertJsonPath('error.code', 'DIGILOCKER_UNAVAILABLE');
    }

    /** Starts and authorizes a connection for the profile; returns its ID. */
    private function connect(?string $profileId): string
    {
        $session = $this->beginDigiLocker($profileId)->assertCreated()->json('data');
        parse_str((string) parse_url($session['authorization_url'], PHP_URL_QUERY), $query);

        $this->asPerson($this->token, $profileId)
            ->postJson("/api/v1/me/digilocker-sessions/{$session['id']}/authorization", ['code' => FakeDigiLocker::CODE, 'state' => $query['state']])
            ->assertOk();

        return $session['id'];
    }

    /** @return TestResponse<JsonResponse> */
    private function beginDigiLocker(?string $profileId): TestResponse
    {
        return $this->asPerson($this->token, $profileId)->postJson('/api/v1/me/digilocker-sessions');
    }
}
