<?php

namespace Tests\Feature\Samples;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Network\Models\Branch;
use App\Modules\Samples\Jobs\FlagMissingSamples;
use App\Modules\Samples\Jobs\MonitorTransitDelays;
use App\Modules\Samples\Models\Manifest;
use App\Modules\Samples\Models\Sample;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Samples\MovesSamples;
use Tests\TestCase;

/** Barcodes, collection, manifests, receipt, rejection and recollection (spec §5.4). */
final class SampleOperationsTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use MovesSamples;
    use RefreshDatabase;

    private User $frontDesk;

    private User $phlebotomist;

    private User $runner;

    private User $labTechnician;

    private string $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoNetwork();

        $this->frontDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->phlebotomist = $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->runner = $this->staff(SystemRole::LogisticsRunner, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->labTechnician = $this->staff(SystemRole::LabTechnician, ['branch_id' => $this->branchId('PATCL1')]);
        $this->patientId = $this->actingAsStaff($this->frontDesk)->registerPatient(['whatsapp_opt_in' => false])['id'];
    }

    public function test_a_sample_rejected_on_receipt_is_redrawn_free_at_the_collecting_branch(): void
    {
        $order = $this->bookPaidOrder($this->frontDesk, $this->patientId, 'PATPSC1', ['CBC'], '350.00');
        $cbc = $this->samplesByTest($this->frontDesk, $order['id'])['CBC'];
        $this->collectSample($this->phlebotomist, $cbc['id'])->assertOk();
        $manifestId = $this->openManifestId($this->runner, 'PATPSC1', 'PATCL1');
        $this->actingAsStaff($this->runner)->postJson("/api/v1/manifests/{$manifestId}/dispatch")->assertOk();
        $this->messages->sent = [];

        $this->receiveManifest($this->labTechnician, $manifestId, [
            ['barcode' => $cbc['barcode'], 'condition' => 'rejected', 'rejection_reason' => 'haemolysed'],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.items.0.condition', 'rejected')
            ->assertJsonPath('data.items.0.sample_status', 'rejected');

        $rejected = $this->actingAsStaff($this->frontDesk)->getJson("/api/v1/samples/{$cbc['barcode']}")
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'haemolysed')
            ->json('data');
        $redraw = $this->getJson("/api/v1/samples/{$rejected['redraw_sample_id']}")
            ->assertJsonPath('data.status', 'pending_collection')
            ->assertJsonPath('data.recollection_of_id', $cbc['id'])
            ->assertJsonPath('data.current_branch_id', $this->branchId('PATPSC1'))
            ->assertJsonPath('data.tests.0.code', 'CBC')
            ->json('data');
        $this->assertNotSame($cbc['barcode'], $redraw['barcode']);

        // The CBC line is marked for recollection and a free line replaces it.
        $items = array_column($this->getJson("/api/v1/orders/{$order['id']}")->json('data.items'), null, 'id');
        $original = $items[$cbc['tests'][0]['order_item_id']];
        $replacement = $items[$redraw['tests'][0]['order_item_id']];
        $this->assertSame('recollect', $original['status']);
        $this->assertSame($original['id'], $replacement['recollection_of_item_id']);
        $this->assertSame(['0.00', '0.00', 'ordered'], [$replacement['mrp_price'], $replacement['net_price'], $replacement['status']]);

        // Branch and patient are both told (SMS: the patient did not opt in to WhatsApp).
        $bodies = array_column($this->messages->sent, 'body');
        $this->assertCount(2, $bodies);
        $this->assertStringContainsString("Redraw with barcode {$redraw['barcode']}", $bodies[0]);
        $this->assertStringContainsString('(Haemolysed). Please visit Boring Road PSC for a free re-collection', $bodies[1]);

        // The redraw travels like any sample, in a new bag.
        $this->collectSample($this->phlebotomist, $redraw['id'])->assertOk();
        $this->assertNotSame($manifestId, $this->openManifestId($this->runner, 'PATPSC1', 'PATCL1'));
    }

    public function test_a_sample_drawn_at_the_lab_is_accessioned_there_and_can_be_rejected(): void
    {
        $labDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATCL1')]);
        $labPhlebotomist = $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('PATCL1')]);
        $order = $this->bookPaidOrder($labDesk, $this->patientId, 'PATCL1', ['CBC'], '350.00');
        $cbc = $this->samplesByTest($labDesk, $order['id'])['CBC'];

        $this->collectSample($labPhlebotomist, $cbc['id'])->assertOk();
        // Tested where it was drawn: no manifest needed.
        $this->assertSame(0, $this->asSystem(fn () => Manifest::query()->count()));

        $this->actingAsStaff($this->labTechnician)->postJson("/api/v1/samples/{$cbc['id']}/reject", ['reason' => 'clotted'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');

        $this->postJson("/api/v1/samples/{$cbc['id']}/receive")->assertOk()->assertJsonPath('data.status', 'received');

        $this->postJson("/api/v1/samples/{$cbc['id']}/reject", ['reason' => 'clotted', 'note' => 'Clot seen on mixing'])
            ->assertCreated()
            ->assertHeader('Location')
            ->assertJsonPath('data.status', 'pending_collection')
            ->assertJsonPath('data.recollection_of_id', $cbc['id']);

        $this->postJson("/api/v1/samples/{$cbc['id']}/reject", ['reason' => 'not-a-reason'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'reason');
    }

    public function test_a_sample_from_another_branch_must_arrive_on_a_manifest(): void
    {
        $order = $this->bookPaidOrder($this->frontDesk, $this->patientId, 'PATPSC1', ['CBC'], '350.00');
        $cbc = $this->samplesByTest($this->frontDesk, $order['id'])['CBC'];
        $this->collectSample($this->phlebotomist, $cbc['id'])->assertOk();

        $this->actingAsStaff($this->labTechnician)->postJson("/api/v1/samples/{$cbc['id']}/receive")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SAMPLE_NEEDS_MANIFEST');
    }

    public function test_sample_generation_is_repeatable_and_covers_add_on_tests(): void
    {
        $order = $this->bookPaidOrder($this->frontDesk, $this->patientId, 'PATPSC1', ['CBC'], '350.00');

        $this->postJson("/api/v1/orders/{$order['id']}/samples")->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/orders/{$order['id']}/samples")->assertOk()->assertJsonCount(1, 'data');

        // An add-on test after booking needs its own container.
        $this->postJson("/api/v1/orders/{$order['id']}/items", ['items' => [$this->testItem('ESR')], 'payment' => ['mode' => 'cash', 'amount' => '150.00']])->assertCreated();
        $this->postJson("/api/v1/orders/{$order['id']}/samples")
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.tests.0.code', 'ESR');
    }

    public function test_an_unpaid_order_gets_no_samples_yet(): void
    {
        $order = $this->bookOrder($this->patientId, 'PATPSC1', ['items' => [$this->testItem('CBC')], 'payment' => ['mode' => 'cash', 'amount' => '100.00']])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data');

        $this->postJson("/api/v1/orders/{$order['id']}/samples")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ORDER_NOT_OPEN_FOR_SAMPLES');
        $this->assertSame(0, $this->asSystem(fn () => Sample::query()->count()));
    }

    public function test_offline_desks_attach_pre_printed_barcodes_before_collection(): void
    {
        $order = $this->bookPaidOrder($this->frontDesk, $this->patientId, 'PATPSC1', ['CBC', 'LIPID'], '950.00');
        $samples = $this->samplesByTest($this->frontDesk, $order['id']);

        $this->postJson("/api/v1/samples/{$samples['CBC']['id']}/assign-barcode", ['barcode' => 'pp-0001234'])
            ->assertOk()
            ->assertJsonPath('data.barcode', 'PP-0001234');
        $this->getJson('/api/v1/samples/PP-0001234')->assertOk()->assertJsonPath('data.id', $samples['CBC']['id']);

        $this->postJson("/api/v1/samples/{$samples['LIPID']['id']}/assign-barcode", ['barcode' => 'PP-0001234'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BARCODE_IN_USE');
        $this->postJson("/api/v1/samples/{$samples['LIPID']['id']}/assign-barcode", ['barcode' => 'bad barcode!'])
            ->assertStatus(422);

        $this->collectSample($this->phlebotomist, $samples['CBC']['id'])->assertOk();
        $this->actingAsStaff($this->phlebotomist)->postJson("/api/v1/samples/{$samples['CBC']['id']}/assign-barcode", ['barcode' => 'PP-0009999'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BARCODE_NOT_CHANGEABLE');
    }

    public function test_manifests_only_take_samples_that_are_here_and_go_to_their_lab(): void
    {
        $order = $this->bookPaidOrder($this->frontDesk, $this->patientId, 'PATPSC1', ['CBC', 'VITD'], '1750.00');
        $samples = $this->samplesByTest($this->frontDesk, $order['id']);
        $this->assertSame($this->branchId('PATREF'), $samples['VITD']['processing_branch_id']);

        // Specialised tests go straight to the reference lab in their own bag.
        $this->collectSample($this->phlebotomist, $samples['CBC']['id'])->assertOk();
        $this->collectSample($this->phlebotomist, $samples['VITD']['id'])->assertOk();
        $toClinical = $this->openManifestId($this->runner, 'PATPSC1', 'PATCL1');
        $toReference = $this->openManifestId($this->runner, 'PATPSC1', 'PATREF');

        $this->postJson('/api/v1/manifests', ['to_branch_id' => $this->branchId('PATCL1')])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MANIFEST_ALREADY_OPEN')
            ->assertJsonPath('error.details.0.manifest_id', $toClinical);
        $this->postJson('/api/v1/manifests', ['to_branch_id' => $this->branchId('PATPSC2')])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DESTINATION_NOT_LAB');

        $this->postJson("/api/v1/manifests/{$toClinical}/samples", ['barcodes' => [$samples['VITD']['barcode']]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SAMPLE_NOT_FOR_DESTINATION')
            ->assertJsonPath('error.details.0.barcode', $samples['VITD']['barcode']);
        $this->postJson("/api/v1/manifests/{$toClinical}/samples", ['barcodes' => ['S999999999']])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'barcodes.0');

        // Taken out of the bag and put back.
        $this->deleteJson("/api/v1/manifests/{$toReference}/samples/{$samples['VITD']['id']}")->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson("/api/v1/manifests/{$toReference}/dispatch")->assertStatus(422)->assertJsonPath('error.code', 'MANIFEST_EMPTY');
        $this->postJson("/api/v1/manifests/{$toReference}/samples", ['barcodes' => [$samples['VITD']['barcode']]])->assertOk()->assertJsonCount(1, 'data.items');

        // Only the sending branch dispatches; afterwards the bag is closed.
        $this->actingAsStaff($this->labTechnician)->getJson("/api/v1/manifests/{$toClinical}")->assertOk();
        $clinicalRunner = $this->staff(SystemRole::LogisticsRunner, ['branch_id' => $this->branchId('PATCL1')]);
        $this->actingAsStaff($clinicalRunner)->postJson("/api/v1/manifests/{$toClinical}/dispatch")->assertForbidden();
        $this->actingAsStaff($this->runner)->postJson("/api/v1/manifests/{$toClinical}/dispatch")->assertOk();
        $this->postJson("/api/v1/manifests/{$toClinical}/samples", ['barcodes' => [$samples['CBC']['barcode']]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MANIFEST_NOT_OPEN');
    }

    public function test_receipt_is_scan_by_scan_and_flags_missing_samples_later(): void
    {
        $order = $this->bookPaidOrder($this->frontDesk, $this->patientId, 'PATPSC1', ['CBC', 'LIPID'], '950.00');
        $samples = $this->samplesByTest($this->frontDesk, $order['id']);
        foreach ($samples as $sample) {
            $this->collectSample($this->phlebotomist, $sample['id'])->assertOk();
        }
        $manifestId = $this->openManifestId($this->runner, 'PATPSC1', 'PATCL1');
        $this->postJson("/api/v1/manifests/{$manifestId}/dispatch")->assertOk();

        // Only the receiving lab scans in.
        $this->receiveManifest($this->runner, $manifestId, [['barcode' => $samples['CBC']['barcode'], 'condition' => 'accepted']])->assertForbidden();

        Queue::fake([FlagMissingSamples::class]);
        $this->receiveManifest($this->labTechnician, $manifestId, [['barcode' => $samples['CBC']['barcode'], 'condition' => 'accepted']])
            ->assertOk()
            ->assertJsonPath('data.status', 'partially_received');
        Queue::assertPushed(FlagMissingSamples::class, fn (FlagMissingSamples $job) => $job->manifestId === $manifestId && $job->delay !== null);

        // A repeated scan is harmless; a contradicting one is a conflict; a stranger is refused.
        $this->receiveManifest($this->labTechnician, $manifestId, [['barcode' => $samples['CBC']['barcode'], 'condition' => 'accepted']])
            ->assertOk()->assertJsonPath('data.status', 'partially_received');
        $this->receiveManifest($this->labTechnician, $manifestId, [['barcode' => $samples['CBC']['barcode'], 'condition' => 'rejected', 'rejection_reason' => 'leaked']])
            ->assertStatus(409)->assertJsonPath('error.code', 'SAMPLE_ALREADY_RECEIVED');
        $this->receiveManifest($this->labTechnician, $manifestId, [['barcode' => 'S999999999', 'condition' => 'accepted']])
            ->assertStatus(422)->assertJsonPath('error.code', 'SAMPLE_NOT_ON_MANIFEST');
        $this->receiveManifest($this->labTechnician, $manifestId, [['barcode' => $samples['LIPID']['barcode'], 'condition' => 'rejected']])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'items.0.rejection_reason');
    }

    public function test_missing_samples_are_flagged_to_both_ends_once(): void
    {
        $order = $this->bookPaidOrder($this->frontDesk, $this->patientId, 'PATPSC1', ['CBC', 'LIPID'], '950.00');
        $samples = $this->samplesByTest($this->frontDesk, $order['id']);
        foreach ($samples as $sample) {
            $this->collectSample($this->phlebotomist, $sample['id'])->assertOk();
        }
        $manifestId = $this->openManifestId($this->runner, 'PATPSC1', 'PATCL1');
        $this->postJson("/api/v1/manifests/{$manifestId}/dispatch")->assertOk();
        $this->messages->sent = [];

        // The sync queue runs the delayed check at once.
        $this->receiveManifest($this->labTechnician, $manifestId, [['barcode' => $samples['CBC']['barcode'], 'condition' => 'accepted']])
            ->assertOk()
            ->assertJsonPath('data.status', 'partially_received');

        $this->assertSame([$this->branchPhone('PATPSC1'), $this->branchPhone('PATCL1')], array_column($this->messages->sent, 'to'));
        $this->assertStringContainsString('1 sample(s) not scanned in', $this->messages->sent[0]['body']);
        $this->getJson("/api/v1/manifests/{$manifestId}")->assertJsonPath('data.missing_flagged_at', fn ($value) => $value !== null);

        dispatch_sync(new FlagMissingSamples($manifestId, $this->organization->id));
        $this->assertCount(2, $this->messages->sent);

        // The tube turns up and is scanned: the manifest completes.
        $this->receiveManifest($this->labTechnician, $manifestId, [['barcode' => $samples['LIPID']['barcode'], 'condition' => 'accepted']])
            ->assertOk()
            ->assertJsonPath('data.status', 'received');
    }

    public function test_samples_in_transit_past_their_stability_are_alerted_once(): void
    {
        $this->asSystem(fn () => LabTest::query()->where('code', 'CBC')->update(['stability_hours' => 4]));
        $order = $this->bookPaidOrder($this->frontDesk, $this->patientId, 'PATPSC1', ['CBC'], '350.00');
        $cbc = $this->samplesByTest($this->frontDesk, $order['id'])['CBC'];
        $collectedAt = CarbonImmutable::now()->subHour()->startOfSecond();

        $this->actingAsStaff($this->phlebotomist)->postJson("/api/v1/samples/{$cbc['id']}/collect", ['collected_at' => $collectedAt->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.stable_until', $collectedAt->addHours(4)->toIso8601ZuluString());
        $manifestId = $this->openManifestId($this->runner, 'PATPSC1', 'PATCL1');
        $this->postJson("/api/v1/manifests/{$manifestId}/dispatch")->assertOk()->assertJsonPath('data.items.0.stability_exceeded', false);
        $this->messages->sent = [];

        dispatch_sync(new MonitorTransitDelays);
        $this->assertSame([], $this->messages->sent);

        $this->travelTo($collectedAt->addHours(5));
        dispatch_sync(new MonitorTransitDelays);
        dispatch_sync(new MonitorTransitDelays);

        $this->assertSame([$this->branchPhone('PATPSC1'), $this->branchPhone('PATCL1')], array_column($this->messages->sent, 'to'));
        $this->assertStringContainsString('1 sample(s) in transit past their stability limit', $this->messages->sent[0]['body']);
        $this->actingAsStaff($this->labTechnician)->getJson("/api/v1/manifests/{$manifestId}")->assertJsonPath('data.items.0.stability_exceeded', true);
    }

    public function test_the_database_allows_one_open_manifest_per_route(): void
    {
        $open = fn (string $manifestNo, string $status) => $this->asSystem(function () use ($manifestNo, $status): Manifest {
            $manifest = new Manifest(['from_branch_id' => $this->branchId('PATPSC1'), 'to_branch_id' => $this->branchId('PATCL1'), 'status' => $status]);
            $manifest->organization_id = $this->organization->id;
            $manifest->manifest_no = $manifestNo;
            $manifest->save();

            return $manifest;
        });

        $open('M-1', 'created');
        $open('M-2', 'dispatched');
        $open('M-3', 'received');

        $this->expectException(UniqueConstraintViolationException::class);
        $open('M-4', 'created');
    }

    public function test_processing_labs_see_the_samples_they_test_but_not_the_orders_or_bills(): void
    {
        $order = $this->bookPaidOrder($this->frontDesk, $this->patientId, 'PATPSC1', ['CBC'], '350.00');
        $cbc = $this->samplesByTest($this->frontDesk, $order['id'])['CBC'];

        // A desk at the clinical lab can take payments and book, yet the PSC's order and invoice stay invisible.
        $labDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATCL1')]);
        $this->actingAsStaff($labDesk)->getJson("/api/v1/samples/{$cbc['barcode']}")->assertOk()->assertJsonPath('data.order.order_no', $order['order_no']);
        $this->getJson("/api/v1/orders/{$order['id']}")->assertNotFound();
        $this->getJson("/api/v1/invoices/{$order['invoices'][0]['id']}")->assertNotFound();
        $this->postJson("/api/v1/orders/{$order['id']}/samples")->assertNotFound();
        $this->assertSame([$cbc['id']], array_column($this->getJson('/api/v1/samples')->assertOk()->json('data'), 'id'));

        // A sibling PSC sees nothing of it.
        $otherDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATPSC2')]);
        $this->actingAsStaff($otherDesk)->getJson("/api/v1/samples/{$cbc['barcode']}")->assertNotFound();
        $this->getJson('/api/v1/samples')->assertOk()->assertJsonCount(0, 'data');
        $otherPhlebotomist = $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('PATPSC2')]);
        $this->collectSample($otherPhlebotomist, $cbc['id'])->assertNotFound();

        // The processing lab sees the sample but cannot collect it for the PSC.
        $labPhlebotomist = $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('PATCL1')]);
        $this->collectSample($labPhlebotomist, $cbc['id'])->assertForbidden();

        // Sample endpoints need a sample permission.
        $branchAdmin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->actingAsStaff($branchAdmin)->getJson("/api/v1/samples/{$cbc['barcode']}")->assertForbidden();
    }

    private function branchPhone(string $branchCode): string
    {
        return $this->asSystem(fn () => (string) Branch::query()->where('branch_code', $branchCode)->value('phone'));
    }
}
