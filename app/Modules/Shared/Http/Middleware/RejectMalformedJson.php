<?php

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel silently treats an unparsable JSON body as empty input, which would
 * surface as confusing "field is required" errors. Reject it as a 400 instead
 * (AGENT_RESTAPI rule 4: 400 = malformed request).
 */
final class RejectMalformedJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $body = $request->getContent();

        if ($request->isJson() && trim($body) !== '') {
            json_decode($body);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new DomainError(ErrorCode::MALFORMED_REQUEST, 'The request body is not valid JSON.', 400);
            }
        }

        return $next($request);
    }
}
