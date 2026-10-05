<?php

namespace App\Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation ID (spec §11 observability). It is added to
 * Laravel's log context, so every log line and audit row can be traced back to
 * one request, and returned to the client in the X-Request-Id header.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->acceptedIncomingId($request) ?? (string) Str::uuid();

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /** Reuse an ID from a gateway or the interface agent if it looks safe. */
    private function acceptedIncomingId(Request $request): ?string
    {
        $incoming = $request->headers->get(self::HEADER);

        if ($incoming === null || preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) !== 1) {
            return null;
        }

        return $incoming;
    }
}
