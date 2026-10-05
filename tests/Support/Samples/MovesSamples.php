<?php

namespace Tests\Support\Samples;

use App\Modules\Auth\Models\User;
use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Catalogue\Models\LabTestCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;

/**
 * Sample journeys on the demo network. Use with BooksOrders, BuildsStaff and
 * RefreshDatabase.
 */
trait MovesSamples
{
    /**
     * Books a fully paid walk-in, which confirms it and generates its samples.
     *
     * @param  list<string>  $testCodes
     * @return array<string, mixed> the order
     */
    protected function bookPaidOrder(User $frontDesk, string $patientId, string $branchCode, array $testCodes, string $amount): array
    {
        return $this->actingAsStaff($frontDesk)
            ->bookOrder($patientId, $branchCode, [
                'items' => array_map(fn (string $code) => $this->testItem($code), $testCodes),
                'payment' => ['mode' => 'cash', 'amount' => $amount],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->json('data');
    }

    /**
     * The order's samples keyed by the code of their first test.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function samplesByTest(User $deskUser, string $orderId): array
    {
        $samples = $this->actingAsStaff($deskUser)->postJson("/api/v1/orders/{$orderId}/samples")->assertOk()->json('data');

        $byTest = [];
        foreach ($samples as $sample) {
            $byTest[$sample['tests'][0]['code']] = $sample;
        }

        return $byTest;
    }

    /** @return TestResponse<JsonResponse> */
    protected function collectSample(User $collector, string $sampleId): TestResponse
    {
        return $this->actingAsStaff($collector)->postJson("/api/v1/samples/{$sampleId}/collect");
    }

    /** The open bag on a route, as the sending branch sees it. */
    protected function openManifestId(User $runner, string $fromCode, string $toCode): string
    {
        $manifests = $this->actingAsStaff($runner)->getJson('/api/v1/manifests?'.http_build_query(['filter' => [
            'status' => 'created',
            'from_branch_id' => $this->branchId($fromCode),
            'to_branch_id' => $this->branchId($toCode),
        ]]))->assertOk()->json('data');

        $this->assertCount(1, $manifests, "Expected one open manifest {$fromCode} → {$toCode}.");

        return $manifests[0]['id'];
    }

    /**
     * @param  list<array<string, mixed>>  $items  barcode + condition (+ rejection_reason)
     * @return TestResponse<JsonResponse>
     */
    protected function receiveManifest(User $receiver, string $manifestId, array $items): TestResponse
    {
        return $this->actingAsStaff($receiver)->postJson("/api/v1/manifests/{$manifestId}/receive", ['items' => $items]);
    }

    /** An analyser breakdown: the lab stops running the test. */
    protected function switchOffCapability(string $labCode, string $testCode): void
    {
        $this->asSystem(fn () => LabTestCapability::query()
            ->where('branch_id', $this->branchId($labCode))
            ->where('test_id', LabTest::query()->where('code', $testCode)->value('id'))
            ->update(['is_active' => false]));
    }
}
