<?php

namespace Tests\Feature\Lab;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Catalogue\Enums\SigningDiscipline;
use App\Modules\Lab\Models\Report;
use App\Modules\Shared\Audit\AuditLog;
use App\Modules\Shared\Notifications\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Lab\RunsLab;
use Tests\Support\Samples\MovesSamples;
use Tests\TestCase;

/**
 * Phase 5 "done when" (spec §12): a signed report reaches the patient on
 * WhatsApp within 2 minutes of release, entirely through the API.
 */
final class ReportJourneyTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use MovesSamples;
    use RefreshDatabase;
    use RunsLab;

    private User $frontDesk;

    private User $phlebotomist;

    private User $runner;

    private User $technician;

    private User $supervisor;

    private User $pathologist;

    private User $biochemist;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpDemoNetwork();
        // 10:30 in India: outside quiet hours.
        CarbonImmutable::setTestNow('2026-10-12 05:00:00');

        $this->frontDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->phlebotomist = $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->runner = $this->staff(SystemRole::LogisticsRunner, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->technician = $this->staff(SystemRole::LabTechnician, ['branch_id' => $this->branchId('PATCL1')]);
        $this->supervisor = $this->staff(SystemRole::LabSupervisor, ['branch_id' => $this->branchId('PATCL1')]);
        $this->pathologist = $this->signatory('PATCL1', ['Haematology'], SigningDiscipline::Pathology);
        $this->biochemist = $this->signatory('PATCL1', ['Biochemistry'], SigningDiscipline::Biochemistry);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_signed_report_reaches_the_patient_on_whatsapp_within_two_minutes_of_release(): void
    {
        $patient = $this->actingAsStaff($this->frontDesk)->registerPatient(['whatsapp_opt_in' => true]);
        $order = $this->orderReceivedAtLab($this->frontDesk, $this->phlebotomist, $this->runner, $this->technician, $patient['id'], ['CBC', 'LIPID'], '950.00');
        $cbc = $this->orderItemId($order, 'CBC');
        $lipid = $this->orderItemId($order, 'LIPID');

        // Scanning the tubes in put both tests on the clinical lab's worklist and opened its report.
        $worklist = $this->actingAsStaff($this->technician)->getJson('/api/v1/worklist?sort=due_at')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['CBC', 'LIPID'], array_column(array_column($worklist, 'test'), 'code'));
        $this->assertSame(['pending', 'pending'], array_column($worklist, 'status'));
        $this->assertSame('Asha Kumari', $worklist[0]['patient']['name']);
        $this->assertSame('draft', $this->currentReport($this->pathologist, $order['id'])['status']);

        // The technician types the CBC and the lipid profile; LDL and VLDL are worked out.
        $this->enterResults($this->technician, $cbc, $this->cbcValues())
            ->assertCreated()
            ->assertJsonPath('data.status', 'entered')
            ->assertJsonPath('data.parameters.0.result.flag', 'normal')
            ->assertJsonPath('data.parameters.0.result.ref_range_text', '12 - 15');
        $lipidResults = $this->enterResults($this->technician, $lipid, ['TC' => '210', 'TG' => '183', 'HDL' => '45'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'entered');
        $lipidParameters = array_column($lipidResults->json('data.parameters'), null, 'code');
        $this->assertSame('128.4', $lipidParameters['LDL']['result']['value']);
        $this->assertSame('high', $lipidParameters['LDL']['result']['flag']);
        $this->assertSame('calculated', $lipidParameters['LDL']['result']['source']);
        $this->assertSame('36.6', $lipidParameters['VLDL']['result']['value']);

        // Correcting a value needs the ETag of the last read; LDL follows the correction.
        $this->enterResults($this->technician, $lipid, ['HDL' => '46'])->assertStatus(428);
        $this->enterResults($this->technician, $lipid, ['HDL' => '46'], '"stale"')->assertStatus(412);
        $corrected = $this->enterResults($this->technician, $lipid, ['HDL' => '46'], $lipidResults->headers->get('ETag'))->assertOk();
        $this->assertSame('127.4', array_column($corrected->json('data.parameters'), null, 'code')['LDL']['result']['value']);

        // The supervisor verifies one lipid value, then each test as a whole.
        $this->actingAsStaff($this->supervisor)
            ->postJson("/api/v1/results/{$lipidParameters['TC']['result']['id']}/verify")
            ->assertOk()
            ->assertJsonPath('data.verified_by', $this->supervisor->id);
        $this->verifyTest($this->supervisor, $cbc)->assertOk()->assertJsonPath('data.status', 'verified');
        $this->verifyTest($this->supervisor, $lipid)->assertOk()->assertJsonPath('data.status', 'verified');

        $report = $this->currentReport($this->pathologist, $order['id']);
        $this->assertSame('pending_signature', $report['status']);

        // Each doctor signs their own department, with their authenticator code.
        $this->actingAsStaff($this->pathologist)
            ->postJson("/api/v1/reports/{$report['id']}/sign", ['code' => '000000'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'MFA_CODE_INVALID');
        $this->signReport($this->pathologist, $report['id'])->assertOk()->assertJsonPath('data.status', 'pending_signature');
        $this->signReport($this->biochemist, $report['id'], [$this->departmentId('Haematology')])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'SIGNATORY_NOT_AUTHORISED');
        $signed = $this->signReport($this->biochemist, $report['id'])->assertOk()->assertJsonPath('data.status', 'signed')->json('data');
        $this->assertSame(['Haematology', 'Biochemistry'], array_column($signed['departments'], 'name'));

        // Release: the PDF is rendered and stored, and the patient gets a WhatsApp link.
        $this->messages->clear();
        $releasedAt = CarbonImmutable::now();
        $released = $this->actingAsStaff($this->biochemist)->postJson("/api/v1/reports/{$report['id']}/release")
            ->assertOk()
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.is_partial', false)
            ->assertJsonPath('data.pdf_ready', true)
            ->json('data');

        $whatsApp = array_values(array_filter($this->messages->sent, fn (array $message) => $message['channel'] === 'whatsapp'));
        $this->assertCount(1, $whatsApp);
        $this->assertSame('9876501234', $whatsApp[0]['to']);
        $this->assertStringContainsString('your lab report for order '.$order['order_no'].' from Patna Clinical Lab is ready', $whatsApp[0]['body']);
        $sentAt = $this->asSystem(fn () => Notification::query()->where('channel', 'whatsapp')->where('payload->report_id', $report['id'])->value('sent_at'));
        $this->assertNotNull($sentAt);
        $this->assertLessThanOrEqual(120, $releasedAt->diffInSeconds(CarbonImmutable::parse($sentAt), absolute: true));

        // The link opens the stored PDF without signing in; its hash matches the record.
        preg_match('#https?://\S+/api/v1/report-links/\S+#', $whatsApp[0]['body'], $link);
        $this->assertNotEmpty($link);
        app('auth')->forgetGuards();
        $pdf = $this->withHeaders(['Authorization' => ''])->get($link[0])->assertOk();
        $this->assertSame($released['pdf_sha256'], hash('sha256', $pdf->streamedContent()));
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());
        $this->get(str_replace('signature=', 'signature=0', $link[0]))->assertStatus(403);

        // The order is complete; every test is reported.
        $orderNow = $this->actingAsStaff($this->frontDesk)->getJson("/api/v1/orders/{$order['id']}")->assertOk()->json('data');
        $this->assertSame('completed', $orderNow['status']);
        $this->assertSame(['reported', 'reported'], array_column($orderNow['items'], 'status'));

        // The QR code proves the report genuine without showing any result.
        $qrCode = $this->asSystem(fn () => Report::query()->whereKey($report['id'])->value('qr_code'));
        $verification = $this->getJson("/api/v1/verify/{$qrCode}")
            ->assertOk()
            ->assertJsonPath('data.status', 'valid')
            ->assertJsonPath('data.patient_initials', 'A.K.')
            ->assertJsonPath('data.lab.name', 'Patna Clinical Lab')
            ->json('data');
        $this->assertEqualsCanonicalizing(['Complete Blood Count', 'Lipid Profile'], $verification['tests']);
        $this->assertStringNotContainsString('127.4', (string) json_encode($verification));

        // Staff download the same file; the view is recorded.
        $this->actingAsStaff($this->pathologist)->get("/api/v1/reports/{$report['id']}/pdf")->assertOk();
        $views = $this->asSystem(fn () => AuditLog::query()->where('entity_id', $report['id'])->where('action', 'report.pdf_viewed')->pluck('new_value')->all());
        $this->assertSame(['signed_link', 'staff'], array_column($views, 'via'));

        // Every report status change is audited.
        $statusChanges = $this->asSystem(fn () => AuditLog::query()
            ->where('entity_id', $report['id'])
            ->where('action', 'report.status_changed')
            ->orderBy('created_at')
            ->get()
            ->map(fn (AuditLog $log) => $log->new_value['status'])
            ->all());
        $this->assertSame(['pending_signature', 'signed', 'released'], $statusChanges);
    }
}
