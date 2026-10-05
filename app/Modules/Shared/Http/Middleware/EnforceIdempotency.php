<?php

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Errors\ErrorCode;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes POSTs that create orders or move money safe to retry (spec §8.5).
 *
 * The first request with a key is processed and its response stored for 24
 * hours; a retry with the same key and body gets the stored response back
 * instead of creating a second order or payment.
 *
 * Attach with the `idempotent` alias, after authentication, so keys are kept
 * per caller.
 */
final class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    private const RETENTION_HOURS = 24;

    /** Response headers worth replaying; everything else is regenerated. */
    private const STORED_HEADERS = ['Content-Type', 'Location', 'ETag'];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::HEADER);

        if (! $request->isMethod('POST') || $key === null) {
            return $next($request);
        }

        $this->assertValidKey($key);

        $actorKey = (string) (Auth::id() ?? 'anonymous');
        $requestHash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        $existing = IdempotencyRecord::query()
            ->where('actor_key', $actorKey)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null && $existing->isExpired()) {
            $existing->delete();
            $existing = null;
        }

        if ($existing !== null) {
            return $this->respondToRepeat($existing, $requestHash);
        }

        $record = $this->claimKey($key, $actorKey, $requestHash, $request);
        $response = $next($request);

        // Server errors are not stored, so the client can retry with the same key.
        if ($response->getStatusCode() >= 500) {
            $record->delete();

            return $response;
        }

        $record->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => (string) $response->getContent(),
            'response_headers' => $this->headersToStore($response),
        ]);

        return $response;
    }

    private function assertValidKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $key) === 1) {
            return;
        }

        throw new DomainError(
            ErrorCode::IDEMPOTENCY_KEY_INVALID,
            'The Idempotency-Key header must be 1 to 100 letters, digits or . _ : - characters.',
            400,
        );
    }

    private function respondToRepeat(IdempotencyRecord $existing, string $requestHash): Response
    {
        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw new DomainError(
                ErrorCode::IDEMPOTENCY_KEY_REUSED,
                'This Idempotency-Key was already used for a different request.',
                422,
            );
        }

        if (! $existing->isCompleted()) {
            throw $this->inProgressError();
        }

        $headers = ($existing->response_headers ?? []) + [self::REPLAYED_HEADER => 'true'];

        return new Response((string) $existing->response_body, (int) $existing->response_status, $headers);
    }

    private function claimKey(string $key, string $actorKey, string $requestHash, Request $request): IdempotencyRecord
    {
        try {
            return IdempotencyRecord::query()->create([
                'idempotency_key' => $key,
                'actor_key' => $actorKey,
                'request_method' => $request->method(),
                'request_path' => mb_substr($request->path(), 0, 255),
                'request_hash' => $requestHash,
                'expires_at' => now()->addHours(self::RETENTION_HOURS),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A parallel request with the same key won the race.
            throw $this->inProgressError();
        }
    }

    private function inProgressError(): DomainError
    {
        return new DomainError(
            ErrorCode::IDEMPOTENCY_REQUEST_IN_PROGRESS,
            'A request with this Idempotency-Key is still being processed. Retry shortly.',
            409,
        );
    }

    /** @return array<string, string> */
    private function headersToStore(Response $response): array
    {
        $headers = [];

        foreach (self::STORED_HEADERS as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }

        return $headers;
    }
}
