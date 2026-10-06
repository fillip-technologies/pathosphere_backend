<?php

namespace Tests\Feature\Lab;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Booking\Models\Invoice;
use App\Modules\Catalogue\Enums\SigningDiscipline;
use App\Modules\Lab\Jobs\DisableExpiredSignatories;
use App\Modules\Lab\Jobs\MonitorTurnaroundTimes;
use App\Modules\Lab\Models\LabResult;
use App\Modules\Lab\Models\Report;
use App\Modules\Lab\Models\Signatory;
use App\Modules\Network\Models\B2bClient;
use App\Modules\Shared\Notifications\Notification;
use App\Modules\Shared\Notifications\NotificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Lab\RunsLab;
use Tests\Support\Samples\MovesSamples;
use Tests\TestCase;

/** Results, reruns, signing rules, amendment, withholding and lab monitors (spec §5.5, §9). */
final class LabOperationsTest extends TestCase
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

    private string $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpDemoNetwork();
        CarbonImmutable::setTestNow('2026-10-12 05:00:00');

        $this->frontDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->phlebotomist = $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->runner = $this->staff(SystemRole::LogisticsRunner, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->technician = $this->staff(SystemRole::LabTechnician, ['branch_id' => $this->branchId('PATCL1')]);
        $this->supervisor = $this->staff(SystemRole::LabSupervisor, ['branch_id' => $this->branchId('PATCL1')]);
        $this->pathologist = $this->signatory('PATCL1', ['Haematology', 'Biochemistry'], SigningDiscipline::Pathology);
        $this->patientId = $this->actingAsStaff($this->frontDesk)->registerPatient(['whatsapp_opt_in' => true])['id'];
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_critical_value_alerts_the_branch_and_the_lab_at_once_even_at_night(): void
    {
        $order = $this->cbcAtLab();
        CarbonImmutable::setTestNow('2026-10-12 17:30:00'); // 23:00 in India: quiet hours
        $this->messages->clear();

        $this->enterResults($this->technician, $this->orderItemId($order, 'CBC'), $this->cbcValues(haemoglobin: '6.4'))
            ->assertCreated()
            ->assertJsonPath('data.parameters.0.result.flag', 'critical_low')
            ->assertJsonPath('data.parameters.0.result.is_critical', true);

        $alerts = array_values(array_filter($this->messages->sent, fn (array $message) => str_starts_with($message['body'], 'CRITICAL')));
        $this->assertCount(2, $alerts, 'The booking branch and the processing lab are both told.');
        $this->assertStringContainsString('Haemoglobin 6.4 g/dL for Asha Kumari', $alerts[0]['body']);

        // Re-sending the same value is not a new critical result.
        $etag = $this->actingAsStaff($this->technician)->getJson("/api/v1/order-items/{$this->orderItemId($order, 'CBC')}/results")->headers->get('ETag');
        $this->messages->clear();
        $this->enterResults($this->technician, $this->orderItemId($order, 'CBC'), ['HB' => '6.4'], $etag)->assertOk();
        $this->assertSame([], $this->messages->sent);
    }

    public function test_invalid_values_are_all_reported_at_once_and_nothing_is_saved(): void
    {
        $lipid = $this->orderItemId($this->lipidAtLab(), 'LIPID');

        $this->enterResults($this->technician, $lipid, ['TC' => 'high', 'LDL' => '100', 'XYZ' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'RESULT_VALUE_INVALID')
            ->assertJsonPath('error.details.0.field', 'results.0.value')
            ->assertJsonPath('error.details.1.code', 'CALCULATED_PARAMETER')
            ->assertJsonPath('error.details.2.code', 'UNKNOWN_PARAMETER');
        $this->assertSame(0, $this->asSystem(fn () => LabResult::query()->count()));
    }

    public function test_a_rerun_keeps_the_first_run_voids_the_signature_and_sends_the_report_back_to_draft(): void
    {
        $order = $this->cbcAtLab();
        $cbc = $this->orderItemId($order, 'CBC');
        $this->enterResults($this->technician, $cbc, $this->cbcValues())->assertCreated();
        $this->verifyTest($this->supervisor, $cbc)->assertOk();
        $report = $this->currentReport($this->pathologist, $order['id']);
        $this->signReport($this->pathologist, $report['id'])->assertOk()->assertJsonPath('data.status', 'signed');

        // Technicians cannot order reruns; supervisors can.
        $this->actingAsStaff($this->technician)->postJson("/api/v1/order-items/{$cbc}/rerun", ['reason' => 'Clot suspected'])->assertForbidden();
        $this->actingAsStaff($this->supervisor)->postJson("/api/v1/order-items/{$cbc}/rerun", ['reason' => 'Clot suspected'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_run', 2)
            ->assertJsonPath('data.parameters.0.result', null)
            ->assertJsonPath('data.earlier_runs.0.run_no', 1)
            ->assertJsonPath('data.earlier_runs.0.is_final', false);

        $this->actingAsStaff($this->pathologist)->getJson("/api/v1/reports/{$report['id']}")
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.signatures', [])
            ->assertJsonPath('data.departments.0.signed_at', null);

        $this->enterResults($this->technician, $cbc, $this->cbcValues('13.0'))->assertCreated()->assertJsonPath('data.current_run', 2);
        $this->verifyTest($this->supervisor, $cbc)->assertOk();
        $this->signReport($this->pathologist, $report['id'])->assertOk()->assertJsonPath('data.status', 'signed');
        $this->assertSame(2, $this->asSystem(fn () => LabResult::query()->where('order_item_id', $cbc)->where('test_parameter_id', LabResult::query()->where('order_item_id', $cbc)->value('test_parameter_id'))->count()));
    }

    public function test_a_released_report_is_corrected_only_through_a_new_version(): void
    {
        $order = $this->cbcAtLab();
        $cbc = $this->orderItemId($order, 'CBC');
        $first = $this->releasedCbcReport($order);

        $this->actingAsStaff($this->supervisor)->postJson("/api/v1/order-items/{$cbc}/rerun", ['reason' => 'Wrong value'])
            ->assertStatus(409)->assertJsonPath('error.code', 'REPORT_ALREADY_RELEASED');
        $this->actingAsStaff($this->technician)->postJson("/api/v1/reports/{$first['id']}/amend", ['reason' => 'Haemoglobin transcribed wrongly'])->assertForbidden();

        $second = $this->actingAsStaff($this->pathologist)->postJson("/api/v1/reports/{$first['id']}/amend", ['reason' => 'Haemoglobin transcribed wrongly'])
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.amendment_reason', 'Haemoglobin transcribed wrongly')
            ->assertJsonPath('data.status', 'pending_signature')
            ->json('data');
        $this->actingAsStaff($this->pathologist)->getJson("/api/v1/reports/{$first['id']}")->assertJsonPath('data.status', 'amended');

        $this->actingAsStaff($this->supervisor)->postJson("/api/v1/order-items/{$cbc}/rerun", ['reason' => 'Wrong value'])->assertOk();
        $this->enterResults($this->technician, $cbc, $this->cbcValues('12.8'))->assertCreated();
        $this->verifyTest($this->supervisor, $cbc)->assertOk();
        $this->signReport($this->pathologist, $second['id'])->assertOk();
        $this->messages->clear();
        $this->actingAsStaff($this->pathologist)->postJson("/api/v1/reports/{$second['id']}/release")->assertOk()->assertJsonPath('data.status', 'released');

        $this->assertStringContainsString('has been corrected', $this->messages->sent[0]['body']);

        // The first PDF is kept, unchanged, beside the new one; its QR code now says it was replaced.
        [$firstPdf, $secondPdf, $firstQr] = $this->asSystem(fn () => [
            Report::query()->whereKey($first['id'])->first(['pdf_path', 'pdf_sha256'])->toArray(),
            Report::query()->whereKey($second['id'])->value('pdf_path'),
            Report::query()->whereKey($first['id'])->value('qr_code'),
        ]);
        $this->assertSame($first['pdf_sha256'], $firstPdf['pdf_sha256']);
        $this->assertSame($firstPdf['pdf_sha256'], hash('sha256', (string) Storage::disk('local')->get($firstPdf['pdf_path'])));
        $this->assertStringEndsWith("{$second['id']}_v2.pdf", (string) $secondPdf);
        $this->getJson("/api/v1/verify/{$firstQr}")->assertOk()->assertJsonPath('data.status', 'superseded')->assertJsonPath('data.latest_version', 2);
        $this->actingAsStaff($this->pathologist)->get("/api/v1/reports/{$first['id']}/pdf")->assertOk();
    }

    public function test_only_an_eligible_signatory_of_the_processing_lab_can_sign(): void
    {
        $order = $this->cbcAtLab();
        $cbc = $this->orderItemId($order, 'CBC');
        $report = $this->currentReport($this->pathologist, $order['id']);

        $this->signReport($this->pathologist, $report['id'], [$this->departmentId('Haematology')])
            ->assertStatus(422)->assertJsonPath('error.code', 'RESULTS_NOT_VERIFIED');
        $this->signReport($this->pathologist, $report['id'], [$this->departmentId('Microbiology')])
            ->assertStatus(422)->assertJsonPath('error.code', 'DEPARTMENT_NOT_ON_REPORT');

        $this->enterResults($this->technician, $cbc, $this->cbcValues())->assertCreated();
        $this->verifyTest($this->supervisor, $cbc)->assertOk();

        $otherLab = $this->signatory('RNCCL1', ['Haematology'], SigningDiscipline::Pathology);
        $this->signReport($otherLab, $report['id'])->assertNotFound();
        $expired = $this->signatory('PATCL1', ['Haematology'], SigningDiscipline::Pathology, validTill: '2026-10-11');
        $this->signReport($expired, $report['id'])->assertStatus(422)->assertJsonPath('error.code', 'NOTHING_TO_SIGN');
        $this->signReport($expired, $report['id'], [$this->departmentId('Haematology')])
            ->assertStatus(403)->assertJsonPath('error.code', 'SIGNATORY_NOT_AUTHORISED');

        // Nobody may release before every department is signed, and only the lab's staff release.
        $this->actingAsStaff($this->pathologist)->postJson("/api/v1/reports/{$report['id']}/release")->assertStatus(409)->assertJsonPath('error.code', 'REPORT_NOT_SIGNED');
        $this->signReport($this->pathologist, $report['id'])->assertOk()->assertJsonPath('data.status', 'signed');
        $this->signReport($this->pathologist, $report['id'])->assertStatus(422)->assertJsonPath('error.code', 'NOTHING_TO_SIGN');
    }

    public function test_reports_follow_the_order_scope_and_labs_only_see_their_own_work(): void
    {
        $order = $this->cbcAtLab();
        $cbc = $this->orderItemId($order, 'CBC');
        $report = $this->currentReport($this->pathologist, $order['id']);
        $pscAdmin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('PATPSC1')]);
        $otherPscAdmin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('PATPSC2')]);
        $otherLabTechnician = $this->staff(SystemRole::LabTechnician, ['branch_id' => $this->branchId('RNCCL1')]);

        // The booking branch follows its order's report; it cannot work on it.
        $this->actingAsStaff($pscAdmin)->getJson("/api/v1/reports/{$report['id']}")->assertOk();
        $this->actingAsStaff($pscAdmin)->postJson("/api/v1/reports/{$report['id']}/release")->assertForbidden();
        $this->actingAsStaff($otherPscAdmin)->getJson("/api/v1/reports/{$report['id']}")->assertNotFound();

        // Another lab sees neither the test nor the report, and cannot enter results for it.
        $this->actingAsStaff($otherLabTechnician)->getJson('/api/v1/worklist')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsStaff($otherLabTechnician)->getJson("/api/v1/order-items/{$cbc}/results")->assertNotFound();
        $this->enterResults($otherLabTechnician, $cbc, $this->cbcValues())->assertStatus(422)->assertJsonPath('error.details.0.field', 'order_item_id');

        // The processing lab still cannot see the booking branch's invoices (spec §4 special rule).
        $invoiceId = $order['invoices'][0]['id'];
        $this->actingAsStaff($this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATCL1')]))
            ->getJson("/api/v1/invoices/{$invoiceId}")
            ->assertNotFound();
    }

    public function test_a_test_leaves_the_worklist_when_its_sample_is_rejected_at_the_lab(): void
    {
        $order = $this->cbcAtLab();
        $sampleId = $this->actingAsStaff($this->technician)->getJson('/api/v1/worklist')->json('data.0.sample.id');

        $this->actingAsStaff($this->technician)->postJson("/api/v1/samples/{$sampleId}/reject", ['reason' => 'clotted'])->assertCreated();

        $this->actingAsStaff($this->technician)->getJson('/api/v1/worklist')->assertJsonCount(0, 'data');
        $this->actingAsStaff($this->technician)->getJson('/api/v1/worklist?filter[status]=withdrawn')->assertJsonCount(1, 'data');
        $this->assertSame('draft', $this->currentReport($this->pathologist, $order['id'])['status']);
    }

    public function test_a_b2b_clients_report_is_withheld_while_its_invoices_are_overdue(): void
    {
        $client = $this->asSystem(function (): B2bClient {
            $client = B2bClient::query()->where('client_code', 'CLPCH')->firstOrFail();
            $client->update(['withhold_reports_when_overdue' => true]);

            return $client;
        });
        $labDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATCL1')]);
        $order = $this->actingAsStaff($labDesk)->bookOrder($this->patientId, 'PATCL1', [
            'order_source' => 'b2b',
            'b2b_client_id' => $client->id,
            'items' => [$this->testItem('CBC')],
        ])->assertCreated()->json('data');
        $sample = $this->samplesByTest($labDesk, $order['id'])['CBC'];
        $labPhlebotomist = $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('PATCL1')]);
        $this->collectSample($labPhlebotomist, $sample['id'])->assertOk();
        $this->actingAsStaff($this->technician)->postJson("/api/v1/samples/{$sample['id']}/receive")->assertOk();

        $cbc = $this->orderItemId($order, 'CBC');
        $this->enterResults($this->technician, $cbc, $this->cbcValues())->assertCreated();
        $this->verifyTest($this->supervisor, $cbc)->assertOk();
        $report = $this->currentReport($this->pathologist, $order['id']);
        $this->signReport($this->pathologist, $report['id'])->assertOk();

        // The client's invoice falls overdue before release.
        $this->asSystem(fn () => Invoice::query()->where('b2b_client_id', $client->id)->update(['due_date' => '2026-10-01']));
        $this->actingAsStaff($this->pathologist)->postJson("/api/v1/reports/{$report['id']}/release")->assertOk()->assertJsonPath('data.status', 'withheld');
        $this->actingAsStaff($this->pathologist)->postJson("/api/v1/reports/{$report['id']}/release")
            ->assertStatus(409)->assertJsonPath('error.code', 'REPORT_WITHHELD_FOR_DUES');

        $this->asSystem(fn () => Invoice::query()->where('b2b_client_id', $client->id)->update(['amount_paid' => DB::raw('total')]));
        $this->actingAsStaff($this->pathologist)->postJson("/api/v1/reports/{$report['id']}/release")->assertOk()->assertJsonPath('data.status', 'released');

        // The client's own user sees the report.
        $clientUser = $this->staff(SystemRole::B2bClientUser, ['b2b_client_id' => $client->id]);
        $this->actingAsStaff($clientUser)->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.0.id', $report['id']);
    }

    public function test_tests_past_their_turnaround_time_are_alerted_once(): void
    {
        $order = $this->cbcAtLab();
        $this->messages->clear();
        CarbonImmutable::setTestNow('2026-10-12 12:30:00'); // CBC is due 6 hours after booking

        dispatch_sync(new MonitorTurnaroundTimes);
        dispatch_sync(new MonitorTurnaroundTimes);

        $tatAlerts = array_values(array_filter($this->messages->sent, fn (array $message) => str_contains($message['body'], 'past their turnaround time')));
        $this->assertCount(2, $tatAlerts, 'The lab and the booking branch, once.');
        $this->assertStringContainsString("Order {$order['order_no']}: 1 test(s) at Patna Clinical Lab", $tatAlerts[0]['body']);
    }

    public function test_signatories_past_their_validity_are_disabled_daily(): void
    {
        $expiring = $this->signatory('PATCL1', ['Serology'], SigningDiscipline::Microbiology, validTill: '2026-10-11');

        dispatch_sync(new DisableExpiredSignatories);

        $this->assertFalse($this->asSystem(fn () => (bool) Signatory::query()->where('user_id', $expiring->id)->value('is_active')));
        $this->assertTrue($this->asSystem(fn () => (bool) Signatory::query()->where('user_id', $this->pathologist->id)->value('is_active')));
    }

    public function test_hq_registers_signatories_whose_discipline_covers_the_department(): void
    {
        $admin = $this->staff(SystemRole::SuperAdmin);
        $doctor = $this->staff(SystemRole::Signatory, ['branch_id' => $this->branchId('PATREF')]);
        $payload = fn (array $overrides = []) => [
            'user_id' => $doctor->id,
            'branch_id' => $this->branchId('PATREF'),
            'department_id' => $this->departmentId('Microbiology'),
            'signing_discipline' => 'microbiology',
            'qualification' => 'MD Microbiology',
            'council_name' => 'Bihar Medical Council',
            'registration_no' => 'BMC-55501',
            'valid_till' => '2027-03-31',
            'signature_image' => UploadedFile::fake()->image('signature.png', 200, 60),
            ...$overrides,
        ];

        $created = $this->actingAsStaff($admin)->post('/api/v1/signatories', $payload(), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.signing_discipline', 'microbiology')
            ->assertJsonMissingPath('data.signature_image_path')
            ->json('data');
        $this->assertTrue(Storage::disk('local')->exists("signatures/{$created['id']}.png"));

        $this->actingAsStaff($admin)->post('/api/v1/signatories', $payload(), ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('error.code', 'SIGNATORY_ALREADY_EXISTS');
        $this->actingAsStaff($admin)->post('/api/v1/signatories', $payload(['department_id' => $this->departmentId('Haematology')]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'SIGNATORY_DISCIPLINE_MISMATCH');
        $this->actingAsStaff($admin)->post('/api/v1/signatories', $payload(['user_id' => $this->technician->id]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'SIGNATORY_USER_NOT_ELIGIBLE');
        $this->actingAsStaff($admin)->post('/api/v1/signatories', $payload(['branch_id' => $this->branchId('PATPSC1')]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'branch_id');
        $this->actingAsStaff($this->pathologist)->getJson('/api/v1/signatories')->assertForbidden();

        $etag = $this->actingAsStaff($admin)->getJson("/api/v1/signatories/{$created['id']}")->assertOk()->headers->get('ETag');
        $this->actingAsStaff($admin)->patchJson("/api/v1/signatories/{$created['id']}", ['valid_till' => '2028-03-31'])->assertStatus(428);
        $this->actingAsStaff($admin)->withHeader('If-Match', (string) $etag)->patchJson("/api/v1/signatories/{$created['id']}", ['valid_till' => '2028-03-31'])
            ->assertOk()->assertJsonPath('data.valid_till', '2028-03-31');
        $this->actingAsStaff($admin)->deleteJson("/api/v1/signatories/{$created['id']}")->assertNoContent();
        $this->actingAsStaff($admin)->getJson("/api/v1/signatories/{$created['id']}")->assertNotFound();
    }

    public function test_delivery_receipts_move_a_message_forward_only(): void
    {
        config(['services.messaging.webhook_secret' => 'dlr-secret']);
        $this->releasedCbcReport($this->cbcAtLab());
        $notification = $this->asSystem(fn () => Notification::query()->where('channel', 'whatsapp')->whereNotNull('provider_message_id')->firstOrFail());
        $post = function (string $status) use ($notification) {
            $body = (string) json_encode(['reports' => [['message_id' => $notification->provider_message_id, 'status' => $status]]]);

            return $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'dlr-secret'),
            ], $body);
        };

        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => 'sha256=bad'], '{}')
            ->assertStatus(401);
        $post('read')->assertOk()->assertJsonPath('data.updated', 1);
        $post('delivered')->assertOk()->assertJsonPath('data.updated', 0);

        $this->assertSame(NotificationStatus::Read, $this->asSystem(fn () => Notification::query()->findOrFail($notification->id)->status));
    }

    /** @return array<string, mixed> the order, with its CBC tube at the clinical lab */
    private function cbcAtLab(): array
    {
        return $this->orderReceivedAtLab($this->frontDesk, $this->phlebotomist, $this->runner, $this->technician, $this->patientId, ['CBC'], '350.00');
    }

    /** @return array<string, mixed> */
    private function lipidAtLab(): array
    {
        return $this->orderReceivedAtLab($this->frontDesk, $this->phlebotomist, $this->runner, $this->technician, $this->patientId, ['LIPID'], '600.00');
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed> the released report
     */
    private function releasedCbcReport(array $order): array
    {
        $cbc = $this->orderItemId($order, 'CBC');
        $this->enterResults($this->technician, $cbc, $this->cbcValues())->assertCreated();
        $this->verifyTest($this->supervisor, $cbc)->assertOk();
        $report = $this->currentReport($this->pathologist, $order['id']);
        $this->signReport($this->pathologist, $report['id'])->assertOk();

        return $this->actingAsStaff($this->pathologist)->postJson("/api/v1/reports/{$report['id']}/release")->assertOk()->json('data');
    }
}
