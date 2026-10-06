<?php

namespace App\Modules\Locker\Http\Controllers;

use App\Modules\Locker\Enums\AccessActorType;
use App\Modules\Locker\Http\Resources\SharedRecordResource;
use App\Modules\Locker\Models\RecordShare;
use App\Modules\Locker\Services\DoctorViewer;
use App\Modules\Locker\Services\SharedRecords;
use App\Modules\Shared\Http\Pagination\CursorPage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A referring doctor's view: only records patients shared with them, while
 * the share is in force (spec §4 Referring Doctor: "reports shared with them
 * by consent").
 */
final class DoctorRecordController
{
    public function __construct(
        private readonly SharedRecords $shared,
        private readonly DoctorViewer $viewer,
    ) {}

    public function index(Request $request): Response
    {
        return CursorPage::respondWith(
            $this->shared->forDoctor($this->viewer->doctor()->id),
            $request,
            fn ($shares) => $shares->map(fn (RecordShare $share) => (new SharedRecordResource($share, $this->shared->patientOf($share->record), false))->resolve($request))->all(),
        );
    }

    public function show(string $recordId): Response
    {
        $doctorId = $this->viewer->doctor()->id;
        $share = $this->shared->doctorShare($doctorId, $recordId);
        $this->shared->logView($share, AccessActorType::Doctor, $doctorId);

        return (new SharedRecordResource($share, $this->shared->patientOf($share->record), true))->response();
    }

    public function file(Request $request, string $recordId): Response
    {
        $doctorId = $this->viewer->doctor()->id;

        return $this->shared->download($this->shared->doctorShare($doctorId, $recordId), AccessActorType::Doctor, $doctorId, $request->integer('version') ?: null);
    }
}
