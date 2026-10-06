<?php

namespace Tests\Support\Locker;

use App\Modules\Booking\Infrastructure\FakeAbdmClient;
use App\Modules\Locker\Contracts\AbdmHipGateway;
use App\Modules\Locker\Infrastructure\FakeAbdmHipGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Plays the ABDM gateway against our labs as Health Information Providers:
 * reads what we sent (the fake gateway records it) and posts ABDM's signed
 * callbacks, built from the payloads in tests/Fixtures/Abdm.
 *
 * Call useFakeAbdmGateway() in setUp, before anything resolves the gateway.
 */
trait PlaysAbdmGateway
{
    private string $abdmSecret = 'abdm-hip-test-secret';

    protected function useFakeAbdmGateway(): void
    {
        config(['services.abdm.callback_secret' => $this->abdmSecret, 'services.abdm.hip_gateway' => 'fake', 'services.abdm.cm_id' => 'sbx']);
        $this->app->forgetInstance(AbdmHipGateway::class);
    }

    protected function abdm(): FakeAbdmHipGateway
    {
        $gateway = $this->app->make(AbdmHipGateway::class);
        $this->assertInstanceOf(FakeAbdmHipGateway::class, $gateway);

        return $gateway;
    }

    /**
     * A recorded callback body with some fields replaced.
     *
     * @param  array<string, mixed>  $overrides  dot paths, e.g. ['notification.consentId' => '…']
     * @return array<string, mixed>
     */
    protected function abdmFixture(string $name, array $overrides = []): array
    {
        $body = json_decode((string) file_get_contents(base_path("tests/Fixtures/Abdm/{$name}.json")), true);
        $this->assertIsArray($body);

        foreach ($overrides as $path => $value) {
            data_set($body, $path, $value);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    protected function abdmCallback(string $type, array $body, string $hipId, ?string $requestId = null, ?string $signature = null): TestResponse
    {
        $raw = (string) json_encode($body);

        return $this->call('POST', "/api/v1/abdm/callbacks/hip/{$type}", server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ABDM_SIGNATURE' => $signature ?? hash_hmac('sha256', $raw, $this->abdmSecret),
            'HTTP_REQUEST_ID' => $requestId ?? (string) Str::uuid(),
            'HTTP_X_HIP_ID' => $hipId,
        ], content: $raw);
    }

    /** @return array{api: string, url: string, headers: array<string, string>, body: array<string, mixed>} */
    protected function lastSentToAbdm(string $api): array
    {
        $call = $this->abdm()->lastSentTo($api);
        $this->assertNotNull($call, "Nothing was sent to ABDM's {$api}.");

        return $call;
    }

    /** Links the ABHA at the front desk (M1), as the logged-in desk user. */
    protected function linkAbhaAtDesk(string $patientId, string $abhaNumber): void
    {
        $txnId = $this->postJson('/api/v1/abha-verifications', ['patient_id' => $patientId, 'abha_number' => $abhaNumber])->assertOk()->json('data.txn_id');
        $this->postJson("/api/v1/abha-verifications/{$txnId}/confirmation", ['otp' => FakeAbdmClient::VALID_OTP])->assertOk();
    }
}
