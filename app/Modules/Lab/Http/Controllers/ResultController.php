<?php

namespace App\Modules\Lab\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Lab\Http\Requests\EnterResultsRequest;
use App\Modules\Lab\Http\Requests\RerunRequest;
use App\Modules\Lab\Http\Resources\LabResultResource;
use App\Modules\Lab\Models\LabResult;
use App\Modules\Lab\Services\ResultEntryService;
use App\Modules\Lab\Services\ResultReviewService;
use App\Modules\Lab\Services\WorklistDetails;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Result entry, verification and reruns (spec §5.5 steps 1–3, §8 Lab). */
final class ResultController
{
    public function __construct(
        private readonly WorklistDetails $details,
        private readonly StaffContext $staff,
    ) {}

    /**
     * POST /results: values for one test. Changing results that are already
     * stored needs If-Match with the test's ETag, so two people never
     * overwrite each other (spec §8.7). 201 when results were first created.
     */
    public function store(EnterResultsRequest $request, ResultEntryService $entry): Response
    {
        $test = $this->details->liveEntryForItem($request->validated('order_item_id'))
            ?? throw ValidationException::withMessages(['order_item_id' => 'This test is not on your lab\'s worklist.']);

        $hasResults = LabResult::query()->where('worklist_entry_id', $test->id)->where('run_no', $test->current_run)->exists();

        if ($hasResults) {
            EntityTag::assertIfMatch($request, $test);
        }

        $changes = $entry->enterByStaff($this->staff, $test, $request->inputs());

        return WorklistController::entryResponse($this->details, $test, $request, ! $hasResults && $changes->created > 0 ? 201 : 200);
    }

    public function verify(LabResult $result, ResultReviewService $review): Response
    {
        return LabResultResource::make($review->verifyResult($this->staff, $result)->refresh())->response();
    }

    /** POST /order-items/{id}/verify: every result of the test's current run. */
    public function verifyTest(Request $request, string $orderItemId, ResultReviewService $review): Response
    {
        $test = $this->details->liveEntryForItem($orderItemId) ?? abort(404);

        return WorklistController::entryResponse($this->details, $review->verifyTest($this->staff, $test), $request);
    }

    public function rerun(RerunRequest $request, string $orderItemId, ResultReviewService $review): Response
    {
        $test = $this->details->liveEntryForItem($orderItemId) ?? abort(404);

        return WorklistController::entryResponse($this->details, $review->rerun($this->staff, $test, $request->validated('reason')), $request);
    }
}
