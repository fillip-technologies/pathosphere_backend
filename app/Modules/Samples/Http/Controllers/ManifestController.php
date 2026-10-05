<?php

namespace App\Modules\Samples\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Samples\Http\Requests\CreateManifestRequest;
use App\Modules\Samples\Http\Requests\DispatchManifestRequest;
use App\Modules\Samples\Http\Requests\ManifestSamplesRequest;
use App\Modules\Samples\Http\Requests\ReceiveManifestRequest;
use App\Modules\Samples\Http\Resources\ManifestResource;
use App\Modules\Samples\Models\Manifest;
use App\Modules\Samples\Services\ManifestService;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Sample manifests between branches and labs (spec §5.4, §8 Logistics). */
final class ManifestController
{
    public function __construct(
        private readonly ManifestService $manifests,
        private readonly StaffContext $staff,
    ) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'status' => 'status',
                'from_branch_id' => 'from_branch_id',
                'to_branch_id' => 'to_branch_id',
            ])
            ->allowSearch(fn ($query, string $manifestNo) => $query->where('manifest_no', $manifestNo))
            ->allowSorts(['created_at', 'dispatched_at', 'received_at'])
            ->apply(Manifest::query());

        return CursorPage::respond($query, $request, ManifestResource::class);
    }

    public function show(Manifest $manifest): Response
    {
        return $this->withItems($manifest);
    }

    public function store(CreateManifestRequest $request): Response
    {
        $user = $this->staff->user();
        $fromBranchId = $request->validated('from_branch_id') ?? $user->branch_id
            ?? throw ValidationException::withMessages(['from_branch_id' => 'Choose the branch the manifest leaves from.']);

        $manifest = $this->manifests->create($user->organization_id, $fromBranchId, $request->validated('to_branch_id'), $request->barcodes());

        return ApiResponse::created(ManifestResource::make($this->manifests->loadItems($manifest)), "/api/v1/manifests/{$manifest->id}");
    }

    public function addSamples(ManifestSamplesRequest $request, Manifest $manifest): Response
    {
        return $this->withItems($this->manifests->addSamples($manifest, $request->barcodes()));
    }

    public function removeSample(Manifest $manifest, string $sampleId): Response
    {
        return $this->withItems($this->manifests->removeSample($manifest, $sampleId));
    }

    public function dispatch(DispatchManifestRequest $request, Manifest $manifest): Response
    {
        $temperature = $request->validated('dispatch_temp_c');

        return $this->withItems($this->manifests->dispatch(
            $this->staff,
            $manifest,
            $request->validated('courier_name'),
            $request->validated('temperature_ok') === null ? null : (bool) $request->validated('temperature_ok'),
            $temperature === null ? null : (string) $temperature,
        ));
    }

    /** Scan-driven: one call per scan or per batch of scans. */
    public function receive(ReceiveManifestRequest $request, Manifest $manifest): Response
    {
        $temperature = $request->validated('receipt_temp_c');

        return $this->withItems($this->manifests->receive(
            $this->staff,
            $manifest,
            $request->scans(),
            $request->validated('temperature_ok') === null ? null : (bool) $request->validated('temperature_ok'),
            $temperature === null ? null : (string) $temperature,
        ));
    }

    private function withItems(Manifest $manifest): Response
    {
        return ManifestResource::make($this->manifests->loadItems($manifest->refresh()))->response();
    }
}
