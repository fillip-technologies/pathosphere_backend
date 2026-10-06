<?php

namespace App\Modules\Locker\Http\Controllers;

use App\Modules\Locker\Enums\AccessActorType;
use App\Modules\Locker\Http\Resources\SharedRecordResource;
use App\Modules\Locker\Services\SharedRecords;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public, rate-limited: a record opened through a link the patient shared.
 * The token is the proof of access; a revoked or expired one is a 404.
 */
final class ShareLinkController
{
    public function __construct(private readonly SharedRecords $shared) {}

    public function show(string $token): Response
    {
        $share = $this->shared->linkShare($token);
        $this->shared->logView($share, AccessActorType::ShareLink, $share->id);

        return (new SharedRecordResource($share, $this->shared->patientOf($share->record), true))->response();
    }

    public function file(Request $request, string $token): Response
    {
        $share = $this->shared->linkShare($token);

        return $this->shared->download($share, AccessActorType::ShareLink, $share->id, $request->integer('version') ?: null);
    }
}
