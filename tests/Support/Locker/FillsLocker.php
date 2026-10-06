<?php

namespace Tests\Support\Locker;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Catalogue\Enums\SigningDiscipline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/**
 * Health-locker scenarios: patients and doctors signing in by SMS code, and
 * reports released at any PSC and lab of the demo network. Use with
 * RunsLab, MovesSamples, BooksOrders, BuildsStaff and RefreshDatabase, and
 * fake the `local` disk.
 */
trait FillsLocker
{
    /** @var array<string, User> staff by "{role}@{branch code}" */
    private array $lockerStaff = [];

    /** Requests a code, reads it from the SMS and signs in; returns the access token. */
    protected function signInByPhone(string $phone, string $accountType = 'patient'): string
    {
        $this->messages->clear();
        $this->postJson('/api/v1/auth/otp-challenges', ['phone' => $phone, 'account_type' => $accountType])->assertStatus(202);

        return $this->postJson('/api/v1/auth/otp-verifications', [
            'phone' => $phone,
            'account_type' => $accountType,
            'code' => $this->lastOtpSentTo($phone),
        ])->assertOk()->assertJsonPath('data.status', 'authenticated')->json('data.tokens.access_token');
    }

    protected function lastOtpSentTo(string $phone): string
    {
        $codes = [];

        foreach ($this->messages->sent as $message) {
            if ($message['channel'] === 'sms' && $message['to'] === $phone && preg_match('/^(\d{6}) is your code/', $message['body'], $match) === 1) {
                $codes[] = $match[1];
            }
        }

        $this->assertNotEmpty($codes, "No sign-in code was sent to {$phone}.");

        return $codes[array_key_last($codes)];
    }

    /** Acts as a signed-in patient or doctor, optionally switched to a family member. */
    protected function asPerson(string $token, ?string $patientId = null): static
    {
        app('auth')->forgetGuards();
        $request = $this->withToken($token);

        return $patientId === null ? $request->withHeader('X-Patient-Id', '') : $request->withHeader('X-Patient-Id', $patientId);
    }

    /**
     * A CBC drawn at the PSC, run at the lab, verified, signed and released.
     *
     * @return array<string, mixed> the released report
     */
    protected function releasedCbc(string $patientId, string $pscCode, string $labCode, string $haemoglobin): array
    {
        $frontDesk = $this->lockerStaff(SystemRole::FrontDesk, $pscCode);
        $order = $this->bookPaidOrder($frontDesk, $patientId, $pscCode, ['CBC'], '350.00');

        // A CBC is one EDTA tube.
        $sample = $this->samplesByTest($frontDesk, $order['id'])['CBC'];
        $this->collectSample($this->lockerStaff(SystemRole::Phlebotomist, $pscCode), $sample['id'])->assertOk();

        $runner = $this->lockerStaff(SystemRole::LogisticsRunner, $pscCode);
        $manifestId = $this->openManifestId($runner, $pscCode, $labCode);
        $this->actingAsStaff($runner)->postJson("/api/v1/manifests/{$manifestId}/dispatch", ['courier_name' => 'Runner'])->assertOk();
        $this->receiveManifest($this->lockerStaff(SystemRole::LabTechnician, $labCode), $manifestId, [['barcode' => $sample['barcode'], 'condition' => 'accepted']])->assertOk();

        $cbc = $this->orderItemId($order, 'CBC');
        $this->enterResults($this->lockerStaff(SystemRole::LabTechnician, $labCode), $cbc, $this->cbcValues($haemoglobin))->assertCreated();
        $this->verifyTest($this->lockerStaff(SystemRole::LabSupervisor, $labCode), $cbc)->assertOk();

        $pathologist = $this->lockerPathologist($labCode);
        $report = $this->currentReport($pathologist, $order['id'], $labCode);
        $this->signReport($pathologist, $report['id'])->assertOk()->assertJsonPath('data.status', 'signed');

        return $this->actingAsStaff($pathologist)->postJson("/api/v1/reports/{$report['id']}/release")
            ->assertOk()
            ->assertJsonPath('data.status', 'released')
            ->json('data');
    }

    /**
     * A released report corrected: amended, signed again and released as the next version.
     *
     * @return array<string, mixed> the new version
     */
    protected function amendAndRelease(string $reportId, string $labCode): array
    {
        $pathologist = $this->lockerPathologist($labCode);
        $next = $this->actingAsStaff($pathologist)
            ->postJson("/api/v1/reports/{$reportId}/amend", ['reason' => 'Patient name spelling corrected.'])
            ->assertCreated()
            ->json('data');
        $this->signReport($pathologist, $next['id'])->assertOk();

        return $this->actingAsStaff($pathologist)->postJson("/api/v1/reports/{$next['id']}/release")->assertOk()->json('data');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<JsonResponse>
     */
    protected function uploadRecord(string $token, array $overrides = []): TestResponse
    {
        return $this->asPerson($token)->post('/api/v1/me/records', [
            'category' => 'prescription',
            'title' => 'Prescription from Dr Sinha',
            'record_date' => '2026-09-01',
            'provider_facility' => 'Sinha Clinic',
            'file' => UploadedFile::fake()->create('prescription.pdf', 120, 'application/pdf'),
            ...$overrides,
        ], ['Accept' => 'application/json']);
    }

    protected function lockerStaff(SystemRole $role, string $branchCode): User
    {
        return $this->lockerStaff["{$role->value}@{$branchCode}"] ??= $this->staff($role, ['branch_id' => $this->branchId($branchCode)]);
    }

    protected function lockerPathologist(string $labCode): User
    {
        return $this->lockerStaff["pathologist@{$labCode}"] ??= $this->signatory($labCode, ['Haematology'], SigningDiscipline::Pathology);
    }
}
