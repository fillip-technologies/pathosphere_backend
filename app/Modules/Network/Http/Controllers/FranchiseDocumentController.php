<?php

namespace App\Modules\Network\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Network\Enums\FranchiseDocumentStatus;
use App\Modules\Network\Enums\FranchiseDocumentType;
use App\Modules\Network\Http\Requests\ReviewFranchiseDocumentRequest;
use App\Modules\Network\Http\Requests\UploadFranchiseDocumentRequest;
use App\Modules\Network\Http\Resources\FranchiseDocumentResource;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\FranchiseDocument;
use App\Modules\Network\Services\FranchiseDocumentService;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** KYC papers (spec §5.1 step 2, §8 Franchises). */
final class FranchiseDocumentController
{
    public function __construct(private readonly FranchiseDocumentService $documents) {}

    public function index(Request $request, Franchise $franchise): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['doc_type' => 'doc_type', 'status' => 'status'])
            ->allowSorts(['created_at', 'expires_on'])
            ->apply(FranchiseDocument::query()->where('franchise_id', $franchise->id));

        return CursorPage::respond($query, $request, FranchiseDocumentResource::class);
    }

    public function store(UploadFranchiseDocumentRequest $request, Franchise $franchise): Response
    {
        $document = $this->documents->upload(
            $franchise,
            FranchiseDocumentType::from($request->validated('doc_type')),
            $request->file('file'),
            $request->validated('expires_on'),
        );

        return ApiResponse::created(FranchiseDocumentResource::make($document), "/api/v1/franchise-documents/{$document->id}");
    }

    public function show(FranchiseDocument $franchiseDocument): Response
    {
        return FranchiseDocumentResource::make($franchiseDocument)->response();
    }

    public function file(FranchiseDocument $franchiseDocument): Response
    {
        return $this->documents->download($franchiseDocument);
    }

    public function verify(ReviewFranchiseDocumentRequest $request, StaffContext $staff, FranchiseDocument $franchiseDocument): Response
    {
        $document = $this->documents->review(
            $staff,
            $franchiseDocument,
            FranchiseDocumentStatus::from($request->validated('decision')),
            $request->validated('rejection_note'),
        );

        return FranchiseDocumentResource::make($document)->response();
    }
}
