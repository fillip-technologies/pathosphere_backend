<?php

namespace App\Modules\Network\Http\Controllers;

use App\Modules\Network\Contracts\ESignProvider;
use App\Modules\Network\Errors\NetworkError;
use App\Modules\Network\Services\AgreementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /webhooks/esign: the vendor tells us an agreement was signed or
 * declined (spec §5.1 step 4). The signature is the authentication; repeats
 * of the same event are answered, never applied twice.
 */
final class ESignWebhookController
{
    public function __invoke(Request $request, ESignProvider $eSign, AgreementService $agreements): JsonResponse
    {
        $rawBody = $request->getContent();

        if (! $eSign->hasValidSignature($rawBody, (string) $request->header('X-Signature', ''))) {
            throw NetworkError::invalidWebhookSignature();
        }

        $event = $eSign->parseWebhook($rawBody)
            ?? throw ValidationException::withMessages(['payload' => 'Expected a reference and a status of signed, declined or expired.']);

        return new JsonResponse(['data' => ['received' => true, 'applied' => $agreements->applySignatureEvent($event)]]);
    }
}
