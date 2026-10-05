<?php

namespace Tests\Support\Booking;

use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Catalogue\Models\Package;
use App\Modules\Network\Models\Branch;
use App\Modules\Shared\Notifications\Contracts\EmailSender;
use App\Modules\Shared\Notifications\Contracts\SmsSender;
use App\Modules\Shared\Notifications\Contracts\WhatsAppSender;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Support\Fakes\RecordingMessageSender;

/**
 * Booking scenarios on the full demo network and catalogue (spec §11.6).
 * Use together with BuildsStaff and RefreshDatabase.
 */
trait BooksOrders
{
    protected RecordingMessageSender $messages;

    protected function setUpDemoNetwork(): void
    {
        config(['pathology.payments.gateway' => 'fake']);
        $this->seed(DatabaseSeeder::class);
        $this->useSeededOrganization();

        $this->messages = new RecordingMessageSender;
        $this->app->instance(SmsSender::class, $this->messages);
        $this->app->instance(WhatsAppSender::class, $this->messages);
        $this->app->instance(EmailSender::class, $this->messages);
    }

    protected function branchId(string $branchCode): string
    {
        return $this->asSystem(fn () => (string) Branch::query()->where('branch_code', $branchCode)->value('id'));
    }

    /** @return array{test_id: string} */
    protected function testItem(string $code): array
    {
        return ['test_id' => $this->asSystem(fn () => (string) LabTest::query()->where('code', $code)->value('id'))];
    }

    /** @return array{package_id: string} */
    protected function packageItem(string $code): array
    {
        return ['package_id' => $this->asSystem(fn () => (string) Package::query()->where('code', $code)->value('id'))];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function registerPatient(array $overrides = []): array
    {
        return $this->postJson('/api/v1/patients', [
            'name' => 'Asha Kumari',
            'gender' => 'female',
            'age_years' => 36,
            'phone' => '9876501234',
            'consent_notice_version' => '2026-10',
            ...$overrides,
        ])->assertCreated()->json('data');
    }

    /**
     * @param  array<string, mixed>  $payload  merged over a walk-in order
     * @return TestResponse<JsonResponse>
     */
    protected function bookOrder(string $patientId, string $branchCode, array $payload): TestResponse
    {
        return $this->postJson('/api/v1/orders', [
            'patient_id' => $patientId,
            'branch_id' => $this->branchId($branchCode),
            'order_source' => 'walk_in',
            ...$payload,
        ]);
    }
}
