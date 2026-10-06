<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Notifications\Contracts\DeliveryReportParser;
use App\Modules\Shared\Notifications\DeliveryStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /webhooks/sms-dlr and /webhooks/whatsapp: delivery receipts from the messaging vendors. */
final class DeliveryWebhookController
{
    public function __invoke(Request $request, DeliveryReportParser $parser, DeliveryStatusService $statuses): JsonResponse
    {
        $rawBody = $request->getContent();

        if (! $parser->hasValidSignature($rawBody, (string) $request->header('X-Signature', ''))) {
            throw new DomainError('WEBHOOK_SIGNATURE_INVALID', 'The webhook signature is not valid.', 401);
        }

        return new JsonResponse(['data' => ['updated' => $statuses->apply($parser->parse($rawBody))]]);
    }
}
