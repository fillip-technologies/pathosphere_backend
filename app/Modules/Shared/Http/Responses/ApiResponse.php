<?php

namespace App\Modules\Shared\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Success responses with the status codes from decision D6: 201 always
 * carries a Location header, 202 for work finished later, 204 for no body.
 */
final class ApiResponse
{
    public static function created(JsonResource $resource, string $location, ?Request $request = null): JsonResponse
    {
        return $resource->toResponse($request ?? request())
            ->setStatusCode(201)
            ->header('Location', $location);
    }

    public static function accepted(JsonResource $resource, ?Request $request = null): JsonResponse
    {
        return $resource->toResponse($request ?? request())->setStatusCode(202);
    }

    public static function noContent(): Response
    {
        return response()->noContent();
    }
}
