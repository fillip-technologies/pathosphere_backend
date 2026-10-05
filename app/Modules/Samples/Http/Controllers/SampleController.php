<?php

namespace App\Modules\Samples\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Samples\Http\Requests\AssignBarcodeRequest;
use App\Modules\Samples\Http\Requests\CollectSampleRequest;
use App\Modules\Samples\Http\Requests\RejectSampleRequest;
use App\Modules\Samples\Http\Requests\RerouteSampleRequest;
use App\Modules\Samples\Http\Resources\SampleDetailResource;
use App\Modules\Samples\Http\Resources\SampleResource;
use App\Modules\Samples\Models\Sample;
use App\Modules\Samples\Services\SampleCollectionService;
use App\Modules\Samples\Services\SampleDetails;
use App\Modules\Samples\Services\SampleRejectionService;
use App\Modules\Samples\Services\SampleReroutingService;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Barcodes, collection and lab handling of samples (spec §5.4, §8 Samples). */
final class SampleController
{
    public function __construct(
        private readonly SampleCollectionService $collection,
        private readonly SampleDetails $details,
        private readonly StaffContext $staff,
    ) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'status' => 'status',
                'order_id' => 'order_id',
                'collected_branch_id' => 'collected_branch_id',
                'processing_branch_id' => 'processing_branch_id',
                'current_branch_id' => 'current_branch_id',
            ])
            ->allowSearch(fn ($query, string $barcode) => $query->where('barcode', $barcode))
            ->allowSorts(['created_at', 'collection_datetime', 'received_at'])
            ->apply(Sample::query());

        return CursorPage::respond($query, $request, SampleResource::class);
    }

    /** GET /samples/{barcode}: scanners look samples up by barcode; the ID works too. */
    public function show(string $barcodeOrId): Response
    {
        $sample = $this->details->find($barcodeOrId) ?? abort(404);

        return $this->detail($sample);
    }

    /**
     * POST /orders/{id}/samples: barcodes and ZPL labels for every container
     * the order needs. 201 when new containers were planned, 200 when the
     * order already had them all.
     */
    public function prepareForOrder(string $orderId): Response
    {
        $prepared = $this->collection->prepareForOrder($orderId);

        return new JsonResponse(
            ['data' => SampleDetailResource::collection($this->details->describe($prepared['samples']))->resolve()],
            $prepared['created'] > 0 ? 201 : 200,
            $prepared['created'] > 0 ? ['Location' => "/api/v1/samples?filter[order_id]={$orderId}"] : [],
        );
    }

    public function assignBarcode(AssignBarcodeRequest $request, Sample $sample): Response
    {
        return $this->detail($this->collection->assignBarcode($sample, $request->validated('barcode')));
    }

    public function collect(CollectSampleRequest $request, Sample $sample): Response
    {
        return $this->detail($this->collection->collect($this->staff, $sample, $request->collectedAt()));
    }

    /** Accession at the lab for a sample drawn there. */
    public function receive(Sample $sample): Response
    {
        return $this->detail($this->collection->receiveAtLab($this->staff, $sample));
    }

    /** Rejects the sample and answers with the redraw now awaiting collection. */
    public function reject(RejectSampleRequest $request, Sample $sample, SampleRejectionService $rejections): Response
    {
        $redraw = $rejections->reject($sample, $request->validated('reason'), $request->validated('note'));

        return $this->detail($redraw, 201);
    }

    public function reroute(RerouteSampleRequest $request, Sample $sample, SampleReroutingService $rerouting): Response
    {
        return $this->detail($rerouting->reroute($sample, $request->validated('processing_branch_id'), $request->validated('reason')));
    }

    private function detail(Sample $sample, int $status = 200): Response
    {
        $response = SampleDetailResource::make($this->details->describe([$sample->refresh()])[0])->response()->setStatusCode($status);

        return $status === 201 ? $response->header('Location', "/api/v1/samples/{$sample->id}") : $response;
    }
}
