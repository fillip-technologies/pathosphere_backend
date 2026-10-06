<?php

namespace Tests\Feature\Locker;

use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Locker\Enums\ReminderStatus;
use App\Modules\Locker\Jobs\ExpireRecordShares;
use App\Modules\Locker\Jobs\SendDueReminders;
use App\Modules\Locker\Models\Consent;
use App\Modules\Locker\Models\MedicalReminder;
use App\Modules\Locker\Models\RecordAccessLog;
use App\Modules\Locker\Models\RecordShare;
use Carbon\CarbonImmutable;
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

/**
 * The patient's own records and who may see them (spec §7.11, §10 ABDM
 * rules): uploads with versions, shares with a doctor, an email address or
 * a link, immediate revocation, expiry, the health profile and reminders.
 */
final class LockerSharingTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use FillsLocker;
    use MovesSamples;
    use RefreshDatabase;
    use RunsLab;

    private const DOCTOR_PHONE = '9811100000';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpDemoNetwork();
        // 10:30 in India: outside quiet hours.
        CarbonImmutable::setTestNow('2026-10-12 05:00:00');

        $frontDesk = $this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1');
        $this->actingAsStaff($frontDesk)->registerPatient(['whatsapp_opt_in' => true]);
        $this->actingAsStaff($frontDesk)->postJson('/api/v1/doctors', ['name' => 'Dr Anil Sinha', 'phone' => self::DOCTOR_PHONE, 'report_delivery' => 'sms'])->assertCreated();
        $this->token = $this->signInByPhone('9876501234');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_uploads_keep_every_version(): void
    {
        $record = $this->uploadRecord($this->token)
            ->assertCreated()
            ->assertHeader('Location')
            ->assertJsonPath('data.source', 'upload')
            ->assertJsonPath('data.category.code', 'prescription')
            ->assertJsonPath('data.documents.0.version', 1)
            ->json('data');
        $firstFile = $this->asPerson($this->token)->get("/api/v1/me/records/{$record['id']}/file")->assertOk()->streamedContent();
        $this->assertSame($record['documents'][0]['checksum'], hash('sha256', $firstFile));

        $updated = $this->asPerson($this->token)->post("/api/v1/me/records/{$record['id']}/documents", [
            'file' => UploadedFile::fake()->image('clearer-scan.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame([2, 1], array_column($updated['documents'], 'version'));
        $this->assertSame('image/jpeg', $updated['documents'][0]['mime_type']);

        // The newest by default, any earlier one on request; nothing was overwritten.
        $this->assertSame($updated['documents'][0]['checksum'], hash('sha256', $this->asPerson($this->token)->get("/api/v1/me/records/{$record['id']}/file")->streamedContent()));
        $this->assertSame($firstFile, $this->asPerson($this->token)->get("/api/v1/me/records/{$record['id']}/file?version=1")->streamedContent());
        $this->asPerson($this->token)->getJson("/api/v1/me/records/{$record['id']}/file?version=9")->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_VERSION_NOT_FOUND');
        $this->assertCount(2, Storage::disk('local')->files('uploads/locker/'.$record['patient_id']));

        // Only PDFs and photos, of a known category, not from the future.
        $this->uploadRecord($this->token, ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertStatus(422)->assertJsonPath('error.details.0.field', 'file');
        $this->uploadRecord($this->token, ['category' => 'astrology'])->assertStatus(422);
        $this->uploadRecord($this->token, ['record_date' => '2026-12-01'])->assertStatus(422);

        // The timeline lists it; filters work; an unknown filter is refused.
        $this->asPerson($this->token)->getJson('/api/v1/me/records?filter[category]=prescription')->assertOk()->assertJsonCount(1, 'data');
        $this->asPerson($this->token)->getJson('/api/v1/me/records?filter[source]=own_lab')->assertOk()->assertJsonCount(0, 'data');
        $this->asPerson($this->token)->getJson('/api/v1/me/records?filter[colour]=red')->assertStatus(422);
        $this->asPerson($this->token)->getJson('/api/v1/me/reports')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_doctor_sees_shared_records_only_while_the_share_is_in_force(): void
    {
        $prescription = $this->uploadRecord($this->token)->json('data.id');
        $scan = $this->uploadRecord($this->token, ['category' => 'imaging', 'title' => 'Chest X-ray'])->json('data.id');

        // Sharing with an unregistered doctor is refused; with ours it works, and the doctor is told.
        $this->share([$prescription], ['type' => 'doctor', 'doctor_phone' => '9811199999'])->assertStatus(422)->assertJsonPath('error.code', 'DOCTOR_NOT_REGISTERED');
        $this->messages->clear();
        $share = $this->share([$prescription], ['type' => 'doctor', 'doctor_phone' => self::DOCTOR_PHONE], 'second_opinion', 3)
            ->assertCreated()
            ->assertJsonPath('data.shared_with', ['type' => 'doctor', 'name' => 'Dr Anil Sinha'])
            ->assertJsonPath('data.status', 'granted')
            ->assertJsonPath('data.expires_at', '2026-10-15T05:00:00Z')
            ->assertJsonMissingPath('data.links')
            ->json('data');
        $this->assertSame([self::DOCTOR_PHONE], array_column($this->messages->sent, 'to'));
        $this->assertStringContainsString('Asha Kumari shared 1 health record(s) with you until 15 Oct 2026', $this->messages->sent[0]['body']);

        // The doctor sees that record, with who it is about, but not the other one.
        $doctorToken = $this->signInByPhone(self::DOCTOR_PHONE, 'doctor');
        $list = $this->asPerson($doctorToken)->getJson('/api/v1/me/shared-records')->assertOk()->json('data');
        $this->assertSame([$prescription], array_column(array_column($list, 'record'), 'id'));
        $this->assertSame('Asha Kumari', $list[0]['patient']['name']);
        $this->assertArrayNotHasKey('phone', $list[0]['patient']);
        $this->asPerson($doctorToken)->getJson("/api/v1/me/shared-records/{$prescription}")->assertOk()->assertJsonPath('data.share.purpose', 'second_opinion');
        $this->asPerson($doctorToken)->get("/api/v1/me/shared-records/{$prescription}/file")->assertOk();
        $this->asPerson($doctorToken)->getJson("/api/v1/me/shared-records/{$scan}")->assertNotFound();

        // The patient sees exactly who opened it.
        $log = $this->asPerson($this->token)->getJson('/api/v1/me/record-access-logs?'.http_build_query(['filter' => ['medical_record_id' => $prescription]]))->json('data');
        $this->assertSame(
            [['doctor', 'Dr Anil Sinha', 'download'], ['doctor', 'Dr Anil Sinha', 'view'], ['patient', null, 'share']],
            array_map(fn (array $row) => [$row['actor_type'], $row['actor_name'], $row['action']], $log),
        );

        // Revoking ends it at once; revoking again changes nothing.
        $this->asPerson($this->token)->deleteJson("/api/v1/me/shares/{$share['id']}")->assertNoContent();
        $this->asPerson($this->token)->deleteJson("/api/v1/me/shares/{$share['id']}")->assertNoContent();
        $this->asPerson($doctorToken)->getJson('/api/v1/me/shared-records')->assertOk()->assertJsonCount(0, 'data');
        $this->asPerson($doctorToken)->getJson("/api/v1/me/shared-records/{$prescription}")->assertNotFound();
        $this->asPerson($this->token)->getJson("/api/v1/me/shares/{$share['id']}")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertSame(['revoked'], RecordShare::query()->pluck('status')->map->value->all());

        // A share ends by itself after its days; the nightly job records it.
        $short = $this->share([$scan], ['type' => 'doctor', 'doctor_phone' => self::DOCTOR_PHONE], 'treatment', 1)->json('data');
        $this->asPerson($doctorToken)->getJson('/api/v1/me/shared-records')->assertJsonCount(1, 'data');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay()->addMinute());
        $doctorToken = $this->signInByPhone(self::DOCTOR_PHONE, 'doctor');
        $this->asPerson($doctorToken)->getJson('/api/v1/me/shared-records')->assertJsonCount(0, 'data');
        $this->token = $this->signInByPhone('9876501234');
        $this->asPerson($this->token)->getJson("/api/v1/me/shares/{$short['id']}")->assertJsonPath('data.status', 'expired');
        dispatch_sync(new ExpireRecordShares);
        $this->assertSame('expired', Consent::query()->find($short['id'])?->status->value);
        $this->assertSame(['expired'], RecordShare::query()->where('consent_id', $short['id'])->pluck('status')->map->value->all());
    }

    public function test_a_link_works_for_anyone_until_it_is_revoked(): void
    {
        $record = $this->uploadRecord($this->token)->json('data.id');
        $share = $this->share([$record], ['type' => 'link'])->assertCreated()->json('data');
        $this->assertCount(1, $share['links']);
        $url = $share['links'][0]['url'];
        $token = basename($url);

        // Only the hash of the link's token is stored, and the list never shows it again.
        $this->assertSame(hash('sha256', $token), RecordShare::query()->value('access_token_hash'));
        $this->assertArrayNotHasKey('links', $this->asPerson($this->token)->getJson('/api/v1/me/shares')->json('data.0'));

        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson($url)->assertOk()->assertJsonPath('data.record.id', $record)->assertJsonPath('data.patient.uhid', fn ($uhid) => is_string($uhid));
        $this->withHeaders(['Authorization' => ''])->get("{$url}/file")->assertOk();
        $this->getJson('/api/v1/shared-records/'.str_repeat('a', 48))->assertNotFound();
        $this->assertSame(['share_link', 'share_link'], RecordAccessLog::query()->whereIn('action', ['view', 'download'])->pluck('actor_type')->map->value->all());

        $this->asPerson($this->token)->deleteJson("/api/v1/me/shares/{$share['id']}")->assertNoContent();
        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson($url)->assertNotFound();
    }

    public function test_an_email_share_sends_the_links_by_email(): void
    {
        $record = $this->uploadRecord($this->token)->json('data.id');
        $this->messages->clear();

        $this->share([$record], ['type' => 'email', 'email' => 'Second.Opinion@Example.com'], 'insurance')
            ->assertCreated()
            ->assertJsonPath('data.shared_with.name', 'second.opinion@example.com')
            ->assertJsonMissingPath('data.links');

        $this->assertSame(['email'], $this->messages->channelsUsed());
        $this->assertSame('second.opinion@example.com', $this->messages->sent[0]['to']);
        $this->assertMatchesRegularExpression('#Prescription from Dr Sinha: http\S+/api/v1/shared-records/[A-Za-z0-9]{48}#', $this->messages->sent[0]['body']);
    }

    public function test_only_your_own_current_records_can_be_shared(): void
    {
        $frontDesk = $this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1');
        $this->actingAsStaff($frontDesk)->registerPatient(['name' => 'Mohan Lal', 'gender' => 'male', 'phone' => '9811122233']);
        $otherToken = $this->signInByPhone('9811122233');
        $theirs = $this->uploadRecord($otherToken)->json('data.id');
        $mine = $this->uploadRecord($this->token)->json('data.id');

        $this->share([$mine, $theirs], ['type' => 'link'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RECORD_NOT_SHAREABLE')
            ->assertJsonPath('error.details.0.field', 'medical_record_ids.1');
        $this->share([$mine], ['type' => 'link'], 'personal', 31)->assertStatus(422);
        $this->share([$mine], ['type' => 'doctor'])->assertStatus(422);
        $this->assertSame(0, Consent::query()->count());

        // Their shares are not mine to see or revoke.
        $theirShare = $this->share([$theirs], ['type' => 'link'], token: $otherToken)->json('data.id');
        $this->asPerson($this->token)->getJson('/api/v1/me/shares')->assertJsonCount(0, 'data');
        $this->asPerson($this->token)->deleteJson("/api/v1/me/shares/{$theirShare}")->assertNotFound();
    }

    public function test_health_profile_and_reminders(): void
    {
        $this->asPerson($this->token)->getJson('/api/v1/me/health-profile')->assertOk()->assertJsonPath('data.blood_group', null);
        $this->asPerson($this->token)->putJson('/api/v1/me/health-profile', ['blood_group' => 'O+', 'allergies' => 'Penicillin'])
            ->assertOk()
            ->assertJsonPath('data.blood_group', 'O+');
        $this->asPerson($this->token)->putJson('/api/v1/me/health-profile', ['blood_group' => 'Z'])->assertStatus(422);
        // PUT replaces the whole profile.
        $this->asPerson($this->token)->putJson('/api/v1/me/health-profile', ['blood_group' => 'O+'])->assertOk()->assertJsonPath('data.allergies', null);

        $this->asPerson($this->token)->postJson('/api/v1/me/reminders', ['remind_at' => '2026-10-01T05:00:00Z', 'message' => 'Late'])->assertStatus(422);
        $reminder = $this->asPerson($this->token)
            ->postJson('/api/v1/me/reminders', ['remind_at' => '2026-10-20T04:30:00Z', 'message' => 'HbA1c test due'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data');
        $dismissed = $this->asPerson($this->token)->postJson('/api/v1/me/reminders', ['remind_at' => '2026-10-20T04:30:00Z', 'message' => 'Not needed'])->json('data');
        $this->asPerson($this->token)->postJson("/api/v1/me/reminders/{$dismissed['id']}/dismiss")->assertOk()->assertJsonPath('data.status', 'dismissed');

        // Nothing is due yet.
        $this->messages->clear();
        dispatch_sync(new SendDueReminders);
        $this->assertSame([], $this->messages->sent);

        // When due, the patient is reminded on WhatsApp (opted in), once.
        CarbonImmutable::setTestNow('2026-10-20 04:45:00');
        dispatch_sync(new SendDueReminders);
        dispatch_sync(new SendDueReminders);
        $this->assertSame(['whatsapp'], $this->messages->channelsUsed());
        $this->assertStringContainsString('HbA1c test due', $this->messages->sent[0]['body']);

        $this->token = $this->signInByPhone('9876501234');
        $this->asPerson($this->token)->getJson("/api/v1/me/reminders/{$reminder['id']}")->assertJsonPath('data.status', 'sent');
        $this->asPerson($this->token)->postJson("/api/v1/me/reminders/{$reminder['id']}/dismiss")->assertStatus(409);
        $this->asPerson($this->token)->getJson('/api/v1/me/reminders?filter[status]=pending')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_every_due_reminder_is_sent_even_beyond_one_page(): void
    {
        $patientId = $this->asPerson($this->token)->getJson('/api/v1/me/family')->json('data.account_holder.id');

        for ($i = 0; $i < 250; $i++) {
            $reminder = new MedicalReminder(['remind_at' => '2026-10-12 04:00:00', 'message' => "Reminder {$i}", 'status' => ReminderStatus::Pending]);
            $reminder->patient_id = $patientId;
            $reminder->save();
        }

        dispatch_sync(new SendDueReminders);

        $this->assertSame(0, MedicalReminder::query()->where('status', ReminderStatus::Pending)->count());
        $this->assertSame(250, MedicalReminder::query()->where('status', ReminderStatus::Sent)->count());
    }

    /**
     * @param  list<string>  $recordIds
     * @param  array<string, string>  $sharedWith
     * @return TestResponse<JsonResponse>
     */
    private function share(array $recordIds, array $sharedWith, string $purpose = 'treatment', ?int $days = null, ?string $token = null): TestResponse
    {
        return $this->asPerson($token ?? $this->token)->postJson('/api/v1/me/shares', array_filter([
            'medical_record_ids' => $recordIds,
            'shared_with' => $sharedWith,
            'purpose' => $purpose,
            'valid_for_days' => $days,
        ], fn ($value) => $value !== null));
    }
}
