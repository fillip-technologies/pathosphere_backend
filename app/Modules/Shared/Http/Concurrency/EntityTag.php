<?php

namespace App\Modules\Shared\Http\Concurrency;

use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Errors\ErrorCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optimistic concurrency for mutable resources (spec §8.7): reads return an
 * ETag, and updates must send it back in If-Match, so two lab staff cannot
 * silently overwrite each other's changes.
 */
final class EntityTag
{
    public static function for(Model $model): string
    {
        $version = $model->getAttribute($model->getUpdatedAtColumn());
        $versionText = $version instanceof \DateTimeInterface ? $version->format('Y-m-d H:i:s.u') : (string) $version;

        return '"'.sha1($model::class.'|'.$model->getKey().'|'.$versionText).'"';
    }

    public static function attach(Response $response, Model $model): Response
    {
        $response->headers->set('ETag', self::for($model));

        return $response;
    }

    /**
     * Throws 428 when the header is missing and 412 when the resource changed
     * since the client last read it.
     */
    public static function assertIfMatch(Request $request, Model $model): void
    {
        $ifMatch = $request->headers->get('If-Match');

        if ($ifMatch === null || trim($ifMatch) === '') {
            throw new DomainError(
                ErrorCode::PRECONDITION_REQUIRED,
                'Send the If-Match header with the ETag from your last read of this resource.',
                428,
            );
        }

        $current = self::for($model);
        $sentTags = array_map(
            fn (string $tag): string => preg_replace('/^W\//', '', trim($tag)) ?? '',
            explode(',', $ifMatch),
        );

        if (in_array('*', $sentTags, true) || in_array($current, $sentTags, true)) {
            return;
        }

        throw new DomainError(
            ErrorCode::PRECONDITION_FAILED,
            'This resource was changed by someone else. Reload it and try again.',
            412,
        );
    }
}
