<?php

namespace App\Modules\Network\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Network\Enums\BranchStatus;
use App\Modules\Network\Http\Requests\BranchRequest;
use App\Modules\Network\Http\Resources\BranchResource;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Services\BranchService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BranchController
{
    public function __construct(private readonly BranchService $branches) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'owner_type' => 'owner_type',
                'branch_type' => 'branch_type',
                'region_id' => 'region_id',
                'franchise_id' => 'franchise_id',
                'status' => 'status',
                'pincode' => 'pincode',
            ])
            ->allowSorts(['name', 'branch_code', 'created_at'])
            ->allowSearch(fn ($query, string $text) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$text}%")
                ->orWhere('branch_code', 'like', "{$text}%")))
            ->apply(Branch::query());

        return CursorPage::respond($query, $request, BranchResource::class);
    }

    public function store(BranchRequest $request, StaffContext $staff): Response
    {
        $branch = $this->branches->create($staff->user()->organization_id, $request->validated());

        return ApiResponse::created(BranchResource::make($branch), "/api/v1/branches/{$branch->id}");
    }

    public function show(Branch $branch): Response
    {
        return EntityTag::attach(BranchResource::make($branch)->response(), $branch);
    }

    public function update(BranchRequest $request, Branch $branch): Response
    {
        EntityTag::assertIfMatch($request, $branch);
        $branch = $this->branches->update($branch, $request->validated());

        return EntityTag::attach(BranchResource::make($branch)->response(), $branch);
    }

    public function destroy(Branch $branch): Response
    {
        $this->branches->delete($branch);

        return ApiResponse::noContent();
    }

    public function activate(Branch $branch): Response
    {
        return $this->respondWithStatus($branch, BranchStatus::Active);
    }

    public function suspend(Branch $branch): Response
    {
        return $this->respondWithStatus($branch, BranchStatus::Suspended);
    }

    public function close(Branch $branch): Response
    {
        return $this->respondWithStatus($branch, BranchStatus::Closed);
    }

    private function respondWithStatus(Branch $branch, BranchStatus $newStatus): Response
    {
        $branch = $this->branches->changeStatus($branch, $newStatus);

        return EntityTag::attach(BranchResource::make($branch)->response(), $branch);
    }
}
