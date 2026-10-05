<?php

namespace App\Modules\Shared\Http\Pagination;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;

/**
 * The one list response shape (decision D2), used by every list endpoint:
 * { "data": [...], "pagination": { "next_cursor": "...", "limit": 50 } }
 *
 * Cursor pagination stays fast on large tables where OFFSET does not.
 */
final class CursorPage
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    /**
     * @param  class-string<JsonResource>  $resourceClass
     */
    public static function respond(Builder $query, Request $request, string $resourceClass): JsonResponse
    {
        $limit = self::limit($request);

        // A unique, time-ordered tie-breaker keeps cursors stable (UUIDv7 ids).
        if (! self::isOrderedByKey($query)) {
            $query->orderBy($query->getModel()->getQualifiedKeyName());
        }

        $page = $query->cursorPaginate(perPage: $limit, cursorName: 'cursor');

        return new JsonResponse([
            'data' => $resourceClass::collection($page->getCollection())->resolve($request),
            'pagination' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'limit' => $limit,
            ],
        ]);
    }

    private static function isOrderedByKey(Builder $query): bool
    {
        $model = $query->getModel();
        $keyColumns = [$model->getKeyName(), $model->getQualifiedKeyName()];

        foreach ($query->getQuery()->orders ?? [] as $order) {
            if (in_array($order['column'] ?? null, $keyColumns, true)) {
                return true;
            }
        }

        return false;
    }

    private static function limit(Request $request): int
    {
        $raw = $request->query('limit');

        if ($raw === null) {
            return self::DEFAULT_LIMIT;
        }

        $limit = filter_var($raw, FILTER_VALIDATE_INT);

        if ($limit === false || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw ValidationException::withMessages([
                'limit' => 'The limit must be a whole number between 1 and '.self::MAX_LIMIT.'.',
            ]);
        }

        return $limit;
    }
}
