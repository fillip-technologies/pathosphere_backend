<?php

namespace App\Modules\Network\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Network\Http\Requests\AgreementRequest;
use App\Modules\Network\Http\Resources\AgreementResource;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\FranchiseAgreement;
use App\Modules\Network\Services\AgreementService;
use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Errors\ErrorCode;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Franchise agreements and e-sign (spec §5.1 steps 3–4, §8 Franchises). */
final class AgreementController
{
    public function __construct(private readonly AgreementService $agreements) {}

    public function index(Request $request, Franchise $franchise): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['status' => 'status', 'billing_model' => 'billing_model'])
            ->allowSorts(['created_at', 'start_date'])
            ->apply(FranchiseAgreement::query()->with('pincodes')->where('franchise_id', $franchise->id));

        return CursorPage::respond($query, $request, AgreementResource::class);
    }

    public function store(AgreementRequest $request, Franchise $franchise): Response
    {
        $agreement = $this->agreements->create($franchise, $request->terms(), $request->pincodes() ?? []);

        return ApiResponse::created(AgreementResource::make($agreement), "/api/v1/agreements/{$agreement->id}");
    }

    public function show(FranchiseAgreement $agreement): Response
    {
        return EntityTag::attach(AgreementResource::make($agreement->load('pincodes'))->response(), $agreement);
    }

    public function update(AgreementRequest $request, FranchiseAgreement $agreement): Response
    {
        EntityTag::assertIfMatch($request, $agreement);
        $agreement = $this->agreements->update($agreement, $request->terms(), $request->pincodes());

        return EntityTag::attach(AgreementResource::make($agreement)->response(), $agreement);
    }

    public function destroy(FranchiseAgreement $agreement): Response
    {
        $this->agreements->delete($agreement);

        return ApiResponse::noContent();
    }

    public function sendForSign(StaffContext $staff, FranchiseAgreement $agreement): Response
    {
        $agreement = $this->agreements->sendForSign($staff, $agreement);

        return EntityTag::attach(AgreementResource::make($agreement->load('pincodes'))->response(), $agreement);
    }

    public function terminate(FranchiseAgreement $agreement): Response
    {
        $agreement = $this->agreements->terminate($agreement);

        return EntityTag::attach(AgreementResource::make($agreement->load('pincodes'))->response(), $agreement);
    }

    public function document(FranchiseAgreement $agreement): Response
    {
        return $this->agreements->downloadSigned($agreement)
            ?? throw new DomainError(ErrorCode::NOT_FOUND, 'This agreement has not been signed yet.', 404);
    }
}
