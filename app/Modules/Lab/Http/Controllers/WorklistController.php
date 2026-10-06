<?php

namespace App\Modules\Lab\Http\Controllers;

use App\Modules\Lab\Enums\WorklistStatus;
use App\Modules\Lab\Http\Resources\LabResultResource;
use App\Modules\Lab\Http\Resources\WorklistEntryResource;
use App\Modules\Lab\Models\LabResult;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Lab\Services\WorklistDetails;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** The lab's worklist (spec §8 Lab: GET /worklist?department&status) and each test's results. */
final class WorklistController
{
    public function __construct(private readonly WorklistDetails $details) {}

    /**
     * Open tests by default (pending and entered); `filter[status]` picks one
     * status, including verified and withdrawn. Oldest due first.
     */
    public function index(Request $request): Response
    {
        $query = WorklistEntry::query();
        ListQuery::from($request)
            ->allowFilters([
                'status' => function ($query, string $status): void {
                    if (WorklistStatus::tryFrom($status) === null) {
                        throw ValidationException::withMessages(['filter.status' => 'Choose one of: '.implode(', ', array_column(WorklistStatus::cases(), 'value')).'.']);
                    }

                    $query->where('status', $status);
                },
                'department_id' => 'department_id',
                'processing_branch_id' => 'processing_branch_id',
                'order_id' => 'order_id',
                'sample_id' => 'sample_id',
            ])
            ->allowSorts(['due_at', 'created_at'])
            ->apply($query);

        if (! $request->has('filter.status')) {
            $query->whereIn('status', [WorklistStatus::Pending, WorklistStatus::Entered]);
        }

        return CursorPage::respondWith($query, $request, fn ($entries) => WorklistEntryResource::collection($this->details->describe($entries))->resolve($request));
    }

    /**
     * GET /order-items/{id}/results: the test at the caller's lab with its
     * current results, plus earlier runs. The ETag goes back in If-Match.
     */
    public function results(Request $request, string $orderItemId): Response
    {
        $entry = $this->details->liveEntryForItem($orderItemId) ?? abort(404);

        return self::entryResponse($this->details, $entry, $request);
    }

    public static function entryResponse(WorklistDetails $details, WorklistEntry $entry, Request $request, int $status = 200): JsonResponse
    {
        $earlierRuns = LabResult::query()
            ->where('worklist_entry_id', $entry->id)
            ->where('run_no', '<', $entry->current_run)
            ->orderBy('run_no')
            ->orderBy('id')
            ->get();

        $response = new JsonResponse(['data' => [
            ...WorklistEntryResource::make($details->describe([$entry->refresh()])[0])->toArray($request),
            'earlier_runs' => LabResultResource::collection($earlierRuns)->resolve($request),
        ]], $status);
        $response->headers->set('ETag', EntityTag::for($entry));

        if ($status === 201) {
            $response->headers->set('Location', "/api/v1/order-items/{$entry->order_item_id}/results");
        }

        return $response;
    }
}
