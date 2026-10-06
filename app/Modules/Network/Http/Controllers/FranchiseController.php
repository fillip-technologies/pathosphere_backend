<?php

namespace App\Modules\Network\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Network\Http\Requests\FranchiseRequest;
use App\Modules\Network\Http\Resources\FranchiseResource;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Services\FranchiseService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Franchises and their lifecycle (spec §5.1, §8 Franchises). */
final class FranchiseController
{
    public function __construct(private readonly FranchiseService $franchises) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['status' => 'status', 'region_id' => 'region_id'])
            ->allowSorts(['name', 'franchise_code', 'created_at'])
            ->allowSearch(fn ($query, string $text) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$text}%")
                ->orWhere('franchise_code', 'like', "{$text}%")))
            ->apply(Franchise::query());

        return CursorPage::respond($query, $request, FranchiseResource::class);
    }

    public function store(FranchiseRequest $request, StaffContext $staff): Response
    {
        $franchise = $this->franchises->create($staff, $request->validated());

        return ApiResponse::created(FranchiseResource::make($franchise), "/api/v1/franchises/{$franchise->id}");
    }

    public function show(Franchise $franchise): Response
    {
        return EntityTag::attach(FranchiseResource::make($franchise)->response(), $franchise);
    }

    public function update(FranchiseRequest $request, StaffContext $staff, Franchise $franchise): Response
    {
        EntityTag::assertIfMatch($request, $franchise);
        $franchise = $this->franchises->update($staff, $franchise, $request->validated());

        return EntityTag::attach(FranchiseResource::make($franchise)->response(), $franchise);
    }

    public function destroy(Franchise $franchise): Response
    {
        $this->franchises->delete($franchise);

        return ApiResponse::noContent();
    }

    public function suspend(Franchise $franchise): Response
    {
        return $this->respond($this->franchises->suspend($franchise));
    }

    public function activate(Franchise $franchise): Response
    {
        return $this->respond($this->franchises->activate($franchise));
    }

    public function terminate(Franchise $franchise): Response
    {
        return $this->respond($this->franchises->terminate($franchise));
    }

    private function respond(Franchise $franchise): Response
    {
        return EntityTag::attach(FranchiseResource::make($franchise)->response(), $franchise);
    }
}
