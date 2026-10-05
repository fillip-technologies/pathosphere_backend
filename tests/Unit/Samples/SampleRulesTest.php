<?php

namespace Tests\Unit\Samples;

use App\Modules\Samples\Domain\LabelContent;
use App\Modules\Samples\Domain\ManifestReceipt;
use App\Modules\Samples\Domain\SampleGrouper;
use App\Modules\Samples\Domain\SamplingLine;
use App\Modules\Samples\Domain\StabilityChecker;
use App\Modules\Samples\Domain\ZplLabel;
use App\Modules\Samples\Enums\ManifestStatus;
use App\Modules\Samples\Enums\SampleStatus;
use App\Modules\Samples\StateMachines\ManifestStateMachine;
use App\Modules\Samples\StateMachines\SampleStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Context\CurrentActor;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class SampleRulesTest extends TestCase
{
    public function test_tests_share_a_container_only_with_the_same_tube_and_lab(): void
    {
        $planned = SampleGrouper::group([
            new SamplingLine('item-cbc', 'test-cbc', 'lab-1', 'EDTA whole blood', 'Lavender top'),
            new SamplingLine('item-lipid', 'test-lipid', 'lab-1', 'Serum', 'Red top'),
            new SamplingLine('item-esr', 'test-esr', 'lab-1', 'EDTA whole blood', 'lavender TOP'),
            new SamplingLine('item-vitd', 'test-vitd', 'lab-ref', 'Serum', 'Red top'),
        ]);

        $this->assertCount(3, $planned);
        $this->assertSame(['item-cbc', 'item-esr'], $planned[0]->orderItemIds);
        $this->assertSame(['test-cbc', 'test-esr'], $planned[0]->testIds);
        $this->assertSame(['item-lipid'], $planned[1]->orderItemIds);
        // Same red top, different lab: a tube cannot travel to two labs.
        $this->assertSame('lab-ref', $planned[2]->processingBranchId);
        $this->assertSame(['item-vitd'], $planned[2]->orderItemIds);
    }

    public function test_nothing_to_draw_plans_no_containers(): void
    {
        $this->assertSame([], SampleGrouper::group([]));
    }

    public function test_a_container_is_as_stable_as_its_most_fragile_test(): void
    {
        $collectedAt = CarbonImmutable::parse('2026-10-09T05:00:00Z');

        $this->assertEquals(CarbonImmutable::parse('2026-10-09T09:00:00Z'), StabilityChecker::stableUntil($collectedAt, [24, 4, null]));
        $this->assertNull(StabilityChecker::stableUntil($collectedAt, [null, null]));
        $this->assertNull(StabilityChecker::stableUntil($collectedAt, []));
    }

    public function test_stability_is_exceeded_only_after_the_limit(): void
    {
        $limit = CarbonImmutable::parse('2026-10-09T09:00:00Z');

        $this->assertFalse(StabilityChecker::isExceeded($limit, $limit));
        $this->assertTrue(StabilityChecker::isExceeded($limit, $limit->addSecond()));
        $this->assertFalse(StabilityChecker::isExceeded(null, $limit->addYear()));
    }

    public function test_the_label_carries_the_barcode_patient_and_tests_and_cannot_be_injected(): void
    {
        $zpl = ZplLabel::render(new LabelContent('S000000042', 'Asha ^XZ Kumari~', '36Y F', 'PATPSC1-0000001', 'Lavender top', ['CBC', 'ESR']));

        $this->assertStringStartsWith('^XA', $zpl);
        $this->assertStringEndsWith('^XZ', $zpl);
        $this->assertStringContainsString('^BCN,70,Y,N,N^FDS000000042^FS', $zpl);
        $this->assertStringContainsString('Asha  XZ Kumari  36Y F', $zpl);
        $this->assertStringContainsString('CBC, ESR', $zpl);
        // Exactly one label: patient text never closes it early.
        $this->assertSame(1, substr_count($zpl, '^XZ'));
    }

    public function test_long_label_lines_are_shortened(): void
    {
        $zpl = ZplLabel::render(new LabelContent('S1', str_repeat('Long name ', 10), '70Y M', 'ORD', 'Red top', []));

        $this->assertMatchesRegularExpression('/\^FD.{1,31}…\^FS/u', $zpl);
    }

    public function test_a_manifest_is_received_once_every_sample_is_scanned(): void
    {
        $this->assertSame(ManifestStatus::PartiallyReceived, ManifestReceipt::statusAfterScans(1, 3));
        $this->assertSame(ManifestStatus::Received, ManifestReceipt::statusAfterScans(3, 3));
    }

    public function test_sample_transitions_follow_the_journey(): void
    {
        $samples = new SampleStateMachine(new AuditLogger(new CurrentActor));

        $this->assertTrue($samples->canTransition(SampleStatus::PendingCollection, SampleStatus::Collected));
        $this->assertTrue($samples->canTransition(SampleStatus::Collected, SampleStatus::InTransit));
        $this->assertTrue($samples->canTransition(SampleStatus::Collected, SampleStatus::Received));
        $this->assertTrue($samples->canTransition(SampleStatus::InTransit, SampleStatus::Received));
        $this->assertTrue($samples->canTransition(SampleStatus::Received, SampleStatus::Rejected));
        $this->assertTrue($samples->canTransition(SampleStatus::Received, SampleStatus::InTransit));
        $this->assertTrue($samples->canTransition(SampleStatus::Stored, SampleStatus::Discarded));

        // Rejection is terminal, and only a received sample is judged.
        $this->assertFalse($samples->canTransition(SampleStatus::Rejected, SampleStatus::Received));
        $this->assertFalse($samples->canTransition(SampleStatus::InTransit, SampleStatus::Rejected));
        $this->assertFalse($samples->canTransition(SampleStatus::PendingCollection, SampleStatus::InTransit));
    }

    public function test_manifest_transitions(): void
    {
        $manifests = new ManifestStateMachine(new AuditLogger(new CurrentActor));

        $this->assertTrue($manifests->canTransition(ManifestStatus::Created, ManifestStatus::Dispatched));
        $this->assertTrue($manifests->canTransition(ManifestStatus::Dispatched, ManifestStatus::PartiallyReceived));
        $this->assertTrue($manifests->canTransition(ManifestStatus::PartiallyReceived, ManifestStatus::Received));
        $this->assertFalse($manifests->canTransition(ManifestStatus::Created, ManifestStatus::Received));
        $this->assertFalse($manifests->canTransition(ManifestStatus::Received, ManifestStatus::Dispatched));
    }
}
