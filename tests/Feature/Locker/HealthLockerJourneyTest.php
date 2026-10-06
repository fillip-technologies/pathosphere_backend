<?php

namespace Tests\Feature\Locker;

use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Lab\Models\Report;
use App\Modules\Locker\Models\MedicalRecord;
use App\Modules\Locker\Models\RecordAccessLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Lab\RunsLab;
use Tests\Support\Locker\FillsLocker;
use Tests\Support\Samples\MovesSamples;
use Tests\TestCase;

/**
 * Phase 7 "done when" (spec §12): a patient sees all their reports and
 * trends across branches, entirely through the API.
 */
final class HealthLockerJourneyTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use FillsLocker;
    use MovesSamples;
    use RefreshDatabase;
    use RunsLab;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpDemoNetwork();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_patient_sees_all_their_reports_and_trends_across_branches(): void
    {
        // Asha registers in Patna; her daughter, on the same phone, later in Ranchi.
        CarbonImmutable::setTestNow('2026-08-10 05:00:00');
        $asha = $this->actingAsStaff($this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1'))->registerPatient();
        $daughter = $this->actingAsStaff($this->lockerStaff(SystemRole::FrontDesk, 'RNCPSC1'))->registerPatient([
            'name' => 'Riya Kumari', 'age_years' => 9, 'guardian_patient_id' => $asha['id'],
        ]);
        $stranger = $this->actingAsStaff($this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1'))->registerPatient([
            'name' => 'Mohan Lal', 'gender' => 'male', 'phone' => '9811122233',
        ]);

        // August in Patna: a low haemoglobin. October in Ranchi: better.
        $august = $this->releasedCbc($asha['id'], 'PATPSC1', 'PATCL1', '11.2');
        CarbonImmutable::setTestNow('2026-10-12 05:00:00');
        $october = $this->releasedCbc($asha['id'], 'RNCPSC1', 'RNCCL1', '12.8');
        $this->releasedCbc($stranger['id'], 'PATPSC1', 'PATCL1', '15.1');

        // Asha signs in with the code sent to her phone.
        $token = $this->signInByPhone('9876501234');

        // Both reports, newest first, from both labs.
        $reports = $this->asPerson($token)->getJson('/api/v1/me/reports')->assertOk()->json('data');
        $this->assertSame(['Ranchi Clinical Lab', 'Patna Clinical Lab'], array_column($reports, 'lab_name'));
        $this->assertSame(['2026-10-12', '2026-08-10'], array_column($reports, 'record_date'));
        $this->assertSame([0, 1], array_column($reports, 'abnormal_count'));
        $this->assertSame('Lab report: Complete Blood Count', $reports[0]['title']);
        $this->assertSame(['own_lab', 'own_lab'], array_column($reports, 'source'));

        // One report: its results, and the very PDF the lab released.
        $augustRecord = $this->asPerson($token)->getJson("/api/v1/me/records/{$reports[1]['id']}")->assertOk()->json('data');
        $haemoglobin = array_column($augustRecord['results'][0]['parameters'], null, 'code')['HB'];
        $this->assertSame(['11.2', 'low', 'g/dL'], [$haemoglobin['value'], $haemoglobin['flag'], $haemoglobin['unit']]);
        $pdf = $this->asPerson($token)->get("/api/v1/me/records/{$reports[1]['id']}/file")->assertOk();
        $this->assertSame($august['pdf_sha256'], hash('sha256', $pdf->streamedContent()));

        // Haemoglobin over time, across Patna and Ranchi.
        $trends = array_column($this->asPerson($token)->getJson('/api/v1/me/trends')->assertOk()->json('data'), null, 'parameter_code');
        $this->assertSame(2, $trends['HB']['point_count']);
        $this->assertSame('up', $trends['HB']['direction']);
        $this->assertSame('12.8', $trends['HB']['latest']['value']);
        $trend = $this->asPerson($token)->getJson('/api/v1/me/trends/hb')->assertOk()->json('data');
        $this->assertSame(['2026-08-10', '2026-10-12'], array_column($trend['points'], 'record_date'));
        $this->assertSame(['Patna Clinical Lab', 'Ranchi Clinical Lab'], array_column($trend['points'], 'lab_name'));
        $this->assertSame(['low', 'normal'], array_column($trend['points'], 'flag'));
        $this->asPerson($token)->getJson('/api/v1/me/trends/NOPE')->assertNotFound();

        // The Ranchi lab corrects its report: the locker shows the new version
        // only, and the trend still has one value per visit.
        $corrected = $this->amendAndRelease($october['id'], 'RNCCL1');
        $reportsNow = $this->asPerson($token)->getJson('/api/v1/me/reports')->assertOk()->json('data');
        $this->assertCount(2, $reportsNow);
        $this->assertNotSame($reports[0]['id'], $reportsNow[0]['id']);
        $this->asPerson($token)->getJson("/api/v1/me/records/{$reports[0]['id']}")
            ->assertOk()
            ->assertJsonPath('data.is_current', false)
            ->assertJsonPath('data.superseded_by_id', $reportsNow[0]['id']);
        $pdfNow = $this->asPerson($token)->get("/api/v1/me/records/{$reportsNow[0]['id']}/file")->assertOk();
        $this->assertSame($corrected['pdf_sha256'], hash('sha256', $pdfNow->streamedContent()));
        $this->assertSame(2, $this->asPerson($token)->getJson('/api/v1/me/trends/HB')->json('data.point_count'));

        // Her daughter is on the family list and can be switched to; nobody else can.
        $family = $this->asPerson($token)->getJson('/api/v1/me/family')->assertOk()->json('data');
        $this->assertSame($asha['id'], $family['account_holder']['id']);
        $this->assertSame([[$daughter['id'], 'child', true]], array_map(fn (array $member) => [$member['patient']['id'], $member['relation'], $member['can_switch']], $family['members']));
        $this->asPerson($token, $daughter['id'])->getJson('/api/v1/me/reports')->assertOk()->assertJsonCount(0, 'data');
        $this->asPerson($token, $stranger['id'])->getJson('/api/v1/me/reports')->assertNotFound()->assertJsonPath('error.code', 'PROFILE_NOT_FOUND');

        // Another patient's records do not exist for Asha, and hers not for him.
        $strangerToken = $this->signInByPhone('9811122233');
        $strangerRecord = $this->asPerson($strangerToken)->getJson('/api/v1/me/reports')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
        $this->asPerson($token)->getJson("/api/v1/me/records/{$strangerRecord}")->assertNotFound();
        $this->asPerson($token)->get("/api/v1/me/records/{$strangerRecord}/file")->assertNotFound();
        $this->asPerson($strangerToken)->getJson("/api/v1/me/records/{$reportsNow[0]['id']}")->assertNotFound();

        // Every opening of Asha's records is on record, and she can see who looked.
        $views = RecordAccessLog::query()->where('medical_record_id', $reports[1]['id'])->get();
        $this->assertSame(['patient'], $views->pluck('actor_type.value')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['view', 'download'], $views->pluck('action.value')->unique()->values()->all());
        $log = $this->asPerson($token)->getJson('/api/v1/me/record-access-logs?'.http_build_query(['filter' => ['medical_record_id' => $reports[1]['id']]]))->assertOk()->json('data');
        $this->assertNotEmpty($log);
        $this->assertSame([$reports[1]['id']], array_values(array_unique(array_column($log, 'medical_record_id'))));

        // Filling the locker again for reports released earlier adds nothing.
        $records = MedicalRecord::query()->count();
        $this->artisan('locker:backfill')->assertSuccessful();
        $this->assertSame($records, MedicalRecord::query()->count());
        $this->assertSame(4, $this->asSystem(fn () => Report::query()->whereIn('status', ['released', 'amended'])->count()));
    }
}
