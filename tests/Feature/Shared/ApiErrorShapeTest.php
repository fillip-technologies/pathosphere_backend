<?php

namespace Tests\Feature\Shared;

use App\Modules\Shared\Errors\DomainError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/** Every failure under /api uses the one error shape (decision D1). */
final class ApiErrorShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/_test')->group(function (): void {
            Route::get('/domain-error', fn () => throw new DomainError('PRICE_MISSING', 'No price for this test.', 422, [['field' => 'items.0.test_id']]));
            Route::post('/validated', fn (Request $request) => $request->validate(['email' => ['required', 'email']]));
            Route::get('/crash', fn () => throw new RuntimeException('secret internal detail'));
            Route::get('/private', fn () => 'ok')->middleware('auth:sanctum');
        });
    }

    public function test_domain_errors_keep_their_code_status_and_details(): void
    {
        $this->getJson('/api/v1/_test/domain-error')
            ->assertStatus(422)
            ->assertExactJson(['error' => [
                'code' => 'PRICE_MISSING',
                'message' => 'No price for this test.',
                'status' => 422,
                'details' => [['field' => 'items.0.test_id']],
            ]]);
    }

    public function test_validation_errors_list_each_failing_field(): void
    {
        $this->postJson('/api/v1/_test/validated', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.status', 422)
            ->assertJsonPath('error.details.0.field', 'email');
    }

    public function test_malformed_json_is_a_400(): void
    {
        $this->call('POST', '/api/v1/_test/validated', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{"email":')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'MALFORMED_REQUEST');
    }

    public function test_unexpected_errors_do_not_leak_internals(): void
    {
        $response = $this->getJson('/api/v1/_test/crash');

        $response->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('secret internal detail', (string) $response->getContent());
    }

    public function test_missing_authentication_is_a_401(): void
    {
        $this->getJson('/api/v1/_test/private')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_unknown_routes_are_a_404_in_the_same_shape(): void
    {
        $this->getJson('/api/v1/no-such-resource')
            ->assertStatus(404)
            ->assertExactJson(['error' => [
                'code' => 'NOT_FOUND',
                'message' => 'The requested resource was not found.',
                'status' => 404,
                'details' => [],
            ]]);
    }

    public function test_every_response_carries_a_request_id(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertHeader('X-Request-Id');

        $this->getJson('/api/v1/health', ['X-Request-Id' => 'agent-batch-0001'])
            ->assertHeader('X-Request-Id', 'agent-batch-0001');
    }
}
