<?php

namespace App\Modules\Locker\Http\Controllers;

use App\Modules\Locker\Enums\ConsentPurpose;
use App\Modules\Locker\Http\Requests\ShareRecordsRequest;
use App\Modules\Locker\Http\Resources\ShareResource;
use App\Modules\Locker\Models\Consent;
use App\Modules\Locker\Services\PatientViewer;
use App\Modules\Locker\Services\RecordSharing;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The patient's shares: each is a consent over some records (spec §8 POST/DELETE /me/shares). */
final class ShareController
{
    public function __construct(
        private readonly RecordSharing $sharing,
        private readonly PatientViewer $viewer,
    ) {}

    public function index(Request $request): Response
    {
        return CursorPage::respondWith(
            $this->sharing->list($this->viewer),
            $request,
            fn ($consents) => $consents->map(fn (Consent $consent) => ShareResource::make($consent)->resolve($request))->all(),
        );
    }

    public function show(string $shareId): Response
    {
        return ShareResource::make($this->sharing->find($this->viewer, $shareId))->response();
    }

    public function store(ShareRecordsRequest $request): Response
    {
        $created = $this->sharing->share(
            $this->viewer,
            $request->validated('medical_record_ids'),
            $request->validated('shared_with'),
            ConsentPurpose::from($request->validated('purpose')),
            $request->days(),
        );

        return ApiResponse::created(new ShareResource($created->consent, $created->links), "/api/v1/me/shares/{$created->consent->id}");
    }

    /** Revokes at once: every link and doctor view stops working. */
    public function destroy(string $shareId): Response
    {
        $this->sharing->revoke($this->viewer, $shareId);

        return ApiResponse::noContent();
    }
}
