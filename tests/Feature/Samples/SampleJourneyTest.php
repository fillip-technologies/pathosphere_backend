<?php

namespace Tests\Feature\Samples;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Samples\MovesSamples;
use Tests\TestCase;

/**
 * Phase 4 "done when" (spec §12): a sample travels PSC → clinical lab →
 * reference lab with full trace, entirely through the API.
 */
final class SampleJourneyTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use MovesSamples;
    use RefreshDatabase;

    private User $frontDesk;

    private User $phlebotomist;

    private User $pscRunner;

    private User $clinicalTechnician;

    private User $clinicalRunner;

    private User $referenceTechnician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoNetwork();

        $this->frontDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->phlebotomist = $this->staff(SystemRole::Phlebotomist, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->pscRunner = $this->staff(SystemRole::LogisticsRunner, ['branch_id' => $this->branchId('PATPSC1')]);
        $this->clinicalTechnician = $this->staff(SystemRole::LabTechnician, ['branch_id' => $this->branchId('PATCL1')]);
        $this->clinicalRunner = $this->staff(SystemRole::LogisticsRunner, ['branch_id' => $this->branchId('PATCL1')]);
        $this->referenceTechnician = $this->staff(SystemRole::LabTechnician, ['branch_id' => $this->branchId('PATREF')]);
    }

    public function test_a_sample_travels_from_psc_through_the_clinical_lab_to_the_reference_lab_with_full_trace(): void
    {
        $patient = $this->actingAsStaff($this->frontDesk)->registerPatient();
        $order = $this->bookPaidOrder($this->frontDesk, $patient['id'], 'PATPSC1', ['CBC', 'LIPID'], '950.00');

        // Confirmation already planned one container per tube type, both for the clinical lab.
        $samples = $this->samplesByTest($this->frontDesk, $order['id']);
        $this->assertSame(['CBC', 'LIPID'], array_keys($samples));
        $lipid = $samples['LIPID'];
        $this->assertSame('pending_collection', $lipid['status']);
        $this->assertSame($this->branchId('PATCL1'), $lipid['processing_branch_id']);
        $this->assertMatchesRegularExpression('/^S\d{9}$/', $lipid['barcode']);
        $this->assertStringContainsString("^FD{$lipid['barcode']}^FS", $lipid['label']['content']);
        $this->assertStringContainsString('Asha Kumari 36Y F', $lipid['label']['content']);

        // Phlebotomist draws both tubes: the order starts, the tubes go into the bag for the clinical lab.
        foreach ($samples as $sample) {
            $this->collectSample($this->phlebotomist, $sample['id'])->assertOk()->assertJsonPath('data.status', 'collected');
        }
        $this->actingAsStaff($this->frontDesk)->getJson("/api/v1/orders/{$order['id']}")->assertJsonPath('data.status', 'in_progress');

        $toClinicalLab = $this->openManifestId($this->pscRunner, 'PATPSC1', 'PATCL1');
        $this->actingAsStaff($this->pscRunner)
            ->postJson("/api/v1/manifests/{$toClinicalLab}/dispatch", ['courier_name' => 'Ravi (runner)', 'temperature_ok' => true, 'dispatch_temp_c' => 6.5])
            ->assertOk()
            ->assertJsonPath('data.status', 'dispatched')
            ->assertJsonPath('data.items.0.sample_status', 'in_transit')
            ->assertJsonCount(2, 'data.items');
        $this->assertStringContainsString('2 sample(s) left Boring Road PSC', $this->messages->sent[array_key_last($this->messages->sent)]['body']);

        // The clinical lab scans both in.
        $this->receiveManifest($this->clinicalTechnician, $toClinicalLab, [
            ['barcode' => $samples['CBC']['barcode'], 'condition' => 'accepted'],
            ['barcode' => $lipid['barcode'], 'condition' => 'accepted'],
        ])->assertOk()->assertJsonPath('data.status', 'received');

        // Its chemistry analyser breaks: no rule sends lipid profiles elsewhere, so the lab picks the reference lab.
        $this->switchOffCapability('PATCL1', 'LIPID');
        $this->actingAsStaff($this->clinicalTechnician)
            ->postJson("/api/v1/samples/{$lipid['id']}/reroute", ['reason' => 'Chemistry analyser down'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NO_ROUTE_FOR_TEST');
        $this->actingAsStaff($this->clinicalTechnician)
            ->postJson("/api/v1/samples/{$lipid['id']}/reroute", ['processing_branch_id' => $this->branchId('PATREF'), 'reason' => 'Chemistry analyser down'])
            ->assertOk()
            ->assertJsonPath('data.processing_branch_id', $this->branchId('PATREF'))
            ->assertJsonPath('data.current_branch_id', $this->branchId('PATCL1'));

        // While it waits at the clinical lab, that lab still sees it only because it holds it.
        $this->actingAsStaff($this->clinicalTechnician)->getJson("/api/v1/samples/{$lipid['barcode']}")->assertOk();

        // Forwarded on a new manifest and received at the reference lab.
        $toReferenceLab = $this->openManifestId($this->clinicalRunner, 'PATCL1', 'PATREF');
        $this->actingAsStaff($this->clinicalRunner)->postJson("/api/v1/manifests/{$toReferenceLab}/dispatch", ['courier_name' => 'BlueDart'])->assertOk();
        $this->receiveManifest($this->referenceTechnician, $toReferenceLab, [['barcode' => $lipid['barcode'], 'condition' => 'accepted']])
            ->assertOk()
            ->assertJsonPath('data.status', 'received');

        // Full trace by barcode, the same for the reference lab and the PSC that drew it.
        foreach ([$this->referenceTechnician, $this->frontDesk] as $viewer) {
            $trace = $this->actingAsStaff($viewer)->getJson("/api/v1/samples/{$lipid['barcode']}")
                ->assertOk()
                ->assertJsonPath('data.status', 'received')
                ->assertJsonPath('data.current_branch_id', $this->branchId('PATREF'))
                ->assertJsonPath('data.order.order_no', $order['order_no'])
                ->assertJsonPath('data.tests.0.code', 'LIPID')
                ->json('data');

            $this->assertSame(
                [['PATPSC1', 'PATCL1', 'accepted'], ['PATCL1', 'PATREF', 'accepted']],
                array_map(fn (array $leg) => [$this->codeOf($leg['from_branch_id']), $this->codeOf($leg['to_branch_id']), $leg['condition']], $trace['journey']),
            );
        }

        // The lipid test now belongs to the reference lab; the CBC stays at the clinical lab.
        $items = array_column($this->actingAsStaff($this->frontDesk)->getJson("/api/v1/orders/{$order['id']}")->json('data.items'), null, 'test_id');
        $this->assertSame($this->branchId('PATREF'), $items[$this->testItem('LIPID')['test_id']]['processing_branch_id']);
        $this->assertSame('collected', $items[$this->testItem('LIPID')['test_id']]['status']);
        $this->assertSame($this->branchId('PATCL1'), $items[$this->testItem('CBC')['test_id']]['processing_branch_id']);

        // Every status change on the way is audited.
        $statusChanges = $this->asSystem(fn () => AuditLog::query()
            ->where('entity_id', $lipid['id'])
            ->where('action', 'sample.status_changed')
            ->orderBy('created_at')
            ->get()
            ->map(fn (AuditLog $log) => $log->new_value['status'])
            ->all());
        $this->assertSame(['collected', 'in_transit', 'received', 'in_transit', 'received'], $statusChanges);
    }

    private function codeOf(string $branchId): string
    {
        foreach (['PATPSC1', 'PATCL1', 'PATREF'] as $code) {
            if ($this->branchId($code) === $branchId) {
                return $code;
            }
        }

        return $branchId;
    }
}
