<?php

namespace Tests\Feature\Shared;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Retrying a POST with the same Idempotency-Key never creates twice (spec §8.5). */
final class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private int $created = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'idempotent'])->prefix('api/v1/_test')->group(function (): void {
            Route::post('/orders', function (Request $request) {
                $this->created++;

                return response()->json(['data' => ['number' => $this->created, 'note' => $request->input('note')]], 201)
                    ->header('Location', "/api/v1/orders/{$this->created}");
            });
            Route::post('/failing', fn () => abort(500));
        });
    }

    public function test_a_retry_returns_the_stored_response_without_creating_again(): void
    {
        $first = $this->postJson('/api/v1/_test/orders', ['note' => 'a'], ['Idempotency-Key' => 'booking-1']);
        $retry = $this->postJson('/api/v1/_test/orders', ['note' => 'a'], ['Idempotency-Key' => 'booking-1']);

        $first->assertCreated()->assertJsonPath('data.number', 1);
        $retry->assertCreated()
            ->assertJsonPath('data.number', 1)
            ->assertHeader('Location', '/api/v1/orders/1')
            ->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, $this->created);
    }

    public function test_requests_without_a_key_are_processed_every_time(): void
    {
        $this->postJson('/api/v1/_test/orders', ['note' => 'a'])->assertJsonPath('data.number', 1);
        $this->postJson('/api/v1/_test/orders', ['note' => 'a'])->assertJsonPath('data.number', 2);
    }

    public function test_reusing_a_key_for_a_different_body_is_refused(): void
    {
        $this->postJson('/api/v1/_test/orders', ['note' => 'a'], ['Idempotency-Key' => 'booking-2'])->assertCreated();

        $this->postJson('/api/v1/_test/orders', ['note' => 'b'], ['Idempotency-Key' => 'booking-2'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_server_errors_are_not_stored_so_the_client_can_retry(): void
    {
        $this->postJson('/api/v1/_test/failing', [], ['Idempotency-Key' => 'retry-me'])->assertStatus(500);

        $this->assertDatabaseMissing('idempotency_keys', ['idempotency_key' => 'retry-me']);
    }

    public function test_an_invalid_key_is_a_400(): void
    {
        $this->postJson('/api/v1/_test/orders', [], ['Idempotency-Key' => 'has spaces'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_INVALID');
    }
}
