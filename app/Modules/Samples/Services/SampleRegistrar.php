<?php

namespace App\Modules\Samples\Services;

use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Samples\Enums\SampleStatus;
use App\Modules\Samples\Models\Sample;
use App\Modules\Samples\Models\SampleOrderItem;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Numbering\NumberSequenceService;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;

/**
 * Creates sample rows awaiting collection, each with a barcode that is unique
 * across the organization (spec §5.4 step 1).
 */
final class SampleRegistrar
{
    /** Never restarts, so it never repeats; pre-printed stock with the same text is skipped. */
    private const DEFAULT_BARCODE_FORMAT = 'S{seq:9}';

    private const BARCODE_SERIES = 'sample_barcode';

    public function __construct(
        private readonly CurrentScope $currentScope,
        private readonly NetworkDirectory $network,
        private readonly NumberSequenceService $sequences,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Call inside the transaction that needs the sample.
     *
     * @param  list<string>  $orderItemIds
     */
    public function register(
        string $organizationId,
        string $orderId,
        string $collectedBranchId,
        string $processingBranchId,
        string $sampleType,
        string $containerType,
        array $orderItemIds,
        ?string $recollectionOfId = null,
    ): Sample {
        $sample = new Sample([
            'order_id' => $orderId,
            'barcode' => $this->nextBarcode($organizationId),
            'sample_type' => $sampleType,
            'container_type' => $containerType,
            'collected_branch_id' => $collectedBranchId,
            'processing_branch_id' => $processingBranchId,
            'current_branch_id' => $collectedBranchId,
            'status' => SampleStatus::PendingCollection,
            'recollection_of_id' => $recollectionOfId,
        ]);
        $sample->organization_id = $organizationId;
        $sample->save();

        foreach ($orderItemIds as $orderItemId) {
            $link = new SampleOrderItem(['order_item_id' => $orderItemId]);
            $link->sample_id = $sample->id;
            $link->save();
        }

        $this->auditLogger->recordCreated('sample.create', $sample);

        return $sample;
    }

    /** Whether any sample in the organization, visible to the caller or not, carries the barcode. */
    public function barcodeIsTaken(string $organizationId, string $barcode): bool
    {
        return $this->currentScope->runAs(
            ScopeContext::system($organizationId),
            fn (): bool => Sample::query()->where('barcode', $barcode)->exists(),
        );
    }

    private function nextBarcode(string $organizationId): string
    {
        $format = (string) ($this->network->organizationSettings($organizationId)['barcode_format'] ?? self::DEFAULT_BARCODE_FORMAT);

        do {
            $barcode = $this->sequences->nextFormatted($organizationId, self::BARCODE_SERIES, $format);
        } while ($this->barcodeIsTaken($organizationId, $barcode));

        return $barcode;
    }
}
