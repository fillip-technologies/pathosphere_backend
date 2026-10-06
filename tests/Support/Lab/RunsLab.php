<?php

namespace Tests\Support\Lab;

use App\Modules\Auth\Domain\Totp;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Auth\Services\StaffAccounts;
use App\Modules\Catalogue\Enums\SigningDiscipline;
use App\Modules\Catalogue\Models\Department;
use App\Modules\Lab\Models\Signatory;
use App\Modules\Shared\Files\PrivateFileStore;
use App\Modules\Shared\Files\PrivatePaths;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;

/**
 * Lab scenarios on the demo network: samples delivered to a lab, results,
 * signatories with authenticator apps. Use with MovesSamples, BooksOrders,
 * BuildsStaff and RefreshDatabase, and fake the `local` disk.
 */
trait RunsLab
{
    /** @var array<string, string> authenticator secrets by user ID */
    protected array $mfaSecrets = [];

    /**
     * A doctor with the Signatory role at the lab, MFA set up, and a
     * signatory row for each named department.
     *
     * @param  list<string>  $departmentNames
     */
    protected function signatory(string $labCode, array $departmentNames, SigningDiscipline $discipline, ?string $validTill = null): User
    {
        $user = $this->staff(SystemRole::Signatory, ['branch_id' => $this->branchId($labCode)]);
        $secret = Totp::generateSecret();

        $this->asSystem(function () use ($user, $secret, $labCode, $departmentNames, $discipline, $validTill): void {
            app(StaffAccounts::class)->for($user)->forceFill(['mfa_secret' => $secret, 'mfa_enabled' => true])->save();

            foreach ($departmentNames as $departmentName) {
                $signatory = new Signatory([
                    'user_id' => $user->id,
                    'branch_id' => $this->branchId($labCode),
                    'department_id' => $this->departmentId($departmentName),
                    'signing_discipline' => $discipline,
                    'qualification' => $discipline === SigningDiscipline::Pathology ? 'MD Pathology' : 'MD Biochemistry',
                    'council_name' => 'Bihar Medical Council',
                    'registration_no' => 'BMC-'.random_int(10000, 99999),
                    'valid_till' => $validTill,
                ]);
                $signatory->id = $signatory->newUniqueId();
                $signatory->organization_id = $this->organization->id;
                $signatory->signature_image_path = app(PrivateFileStore::class)->putNew(PrivatePaths::signature($signatory->id), $this->signaturePng())->path;
                $signatory->save();
            }
        });

        $this->mfaSecrets[$user->id] = $secret;

        return $user;
    }

    protected function signingCode(User $signatory): string
    {
        return Totp::codeAt($this->mfaSecrets[$signatory->id], CarbonImmutable::now()->getTimestamp());
    }

    protected function departmentId(string $name): string
    {
        return $this->asSystem(fn () => (string) Department::query()->where('name', $name)->value('id'));
    }

    /**
     * Books a paid walk-in at the PSC, draws every tube and sends them to
     * the clinical lab, which scans them in.
     *
     * @param  list<string>  $testCodes
     * @return array<string, mixed> the order
     */
    protected function orderReceivedAtLab(User $frontDesk, User $phlebotomist, User $runner, User $labStaff, string $patientId, array $testCodes, string $amount): array
    {
        $order = $this->bookPaidOrder($frontDesk, $patientId, 'PATPSC1', $testCodes, $amount);
        $samples = $this->samplesByTest($frontDesk, $order['id']);

        foreach ($samples as $sample) {
            $this->collectSample($phlebotomist, $sample['id'])->assertOk();
        }

        $manifestId = $this->openManifestId($runner, 'PATPSC1', 'PATCL1');
        $this->actingAsStaff($runner)->postJson("/api/v1/manifests/{$manifestId}/dispatch", ['courier_name' => 'Runner'])->assertOk();
        $this->receiveManifest($labStaff, $manifestId, array_values(array_map(
            fn (array $sample) => ['barcode' => $sample['barcode'], 'condition' => 'accepted'],
            $samples,
        )))->assertOk();

        return $order;
    }

    /** @param  array<string, mixed>  $order */
    protected function orderItemId(array $order, string $testCode): string
    {
        $testId = $this->testItem($testCode)['test_id'];

        foreach ($order['items'] as $item) {
            if ($item['test_id'] === $testId) {
                return $item['id'];
            }
        }

        $this->fail("Order has no {$testCode} line.");
    }

    /**
     * @param  array<string, string>  $values  parameter code => value
     * @return TestResponse<JsonResponse>
     */
    protected function enterResults(User $technician, string $orderItemId, array $values, ?string $etag = null): TestResponse
    {
        $request = $this->actingAsStaff($technician);

        if ($etag !== null) {
            $request = $request->withHeader('If-Match', $etag);
        }

        return $request->postJson('/api/v1/results', [
            'order_item_id' => $orderItemId,
            'results' => array_map(
                fn (string $code, string $value) => ['parameter_code' => $code, 'value' => $value],
                array_keys($values),
                $values,
            ),
        ]);
    }

    /** @return TestResponse<JsonResponse> */
    protected function verifyTest(User $supervisor, string $orderItemId): TestResponse
    {
        return $this->actingAsStaff($supervisor)->postJson("/api/v1/order-items/{$orderItemId}/verify");
    }

    /** @return array<string, mixed> the lab's current report for the order */
    protected function currentReport(User $viewer, string $orderId, string $labCode = 'PATCL1'): array
    {
        $reports = $this->actingAsStaff($viewer)->getJson('/api/v1/reports?'.http_build_query(['filter' => [
            'order_id' => $orderId,
            'processing_branch_id' => $this->branchId($labCode),
        ]]))->assertOk()->json('data');

        $current = array_values(array_filter($reports, fn (array $report) => $report['status'] !== 'amended'));
        $this->assertCount(1, $current, 'Expected one current report for the order at the lab.');

        return $current[0];
    }

    /**
     * @param  list<string>|null  $departmentIds
     * @return TestResponse<JsonResponse>
     */
    protected function signReport(User $signatory, string $reportId, ?array $departmentIds = null): TestResponse
    {
        return $this->actingAsStaff($signatory)->postJson("/api/v1/reports/{$reportId}/sign", array_filter([
            'code' => $this->signingCode($signatory),
            'department_ids' => $departmentIds,
        ]));
    }

    /**
     * The CBC of a healthy adult woman, haemoglobin aside.
     *
     * @return array<string, string>
     */
    protected function cbcValues(string $haemoglobin = '13.2'): array
    {
        return ['HB' => $haemoglobin, 'TLC' => '7.1', 'PLT' => '250', 'RBC' => '4.6', 'PCV' => '41'];
    }

    private function signaturePng(): string
    {
        // A 1×1 transparent PNG: enough for the report to embed.
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    }
}
