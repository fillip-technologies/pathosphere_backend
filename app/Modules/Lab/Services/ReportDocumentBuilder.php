<?php

namespace App\Modules\Lab\Services;

use App\Modules\Auth\Services\StaffDirectory;
use App\Modules\Booking\Services\OrderReporting;
use App\Modules\Catalogue\Services\TestDirectory;
use App\Modules\Lab\Contracts\QrCodeRenderer;
use App\Modules\Lab\Models\LabResult;
use App\Modules\Lab\Models\Report;
use App\Modules\Lab\Models\ReportSignature;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Samples\Services\LabSamples;
use App\Modules\Shared\Files\PrivateFileStore;
use Carbon\CarbonImmutable;

/**
 * Everything printed on a report PDF (spec §5.5, §10): the brand, the
 * collection centre and the processing lab with its NABL details, the
 * patient, each department's final results with flags and reference ranges,
 * the signature of the doctor who signed each department, and the QR code of
 * the public verification page.
 */
final class ReportDocumentBuilder
{
    private const TIMEZONE = 'Asia/Kolkata';

    public function __construct(
        private readonly OrderReporting $orders,
        private readonly NetworkDirectory $network,
        private readonly TestDirectory $tests,
        private readonly LabSamples $samples,
        private readonly StaffDirectory $staffDirectory,
        private readonly ReportAssembly $reports,
        private readonly PrivateFileStore $files,
        private readonly QrCodeRenderer $qrCodes,
    ) {}

    public function html(Report $report): string
    {
        return view('reports.lab-report', ['document' => $this->build($report)])->render();
    }

    /** @return array<string, mixed> */
    public function build(Report $report): array
    {
        $order = $this->orders->reportFacts($report->organization_id, $report->order_id);
        $entries = $this->reports->liveEntries($report);
        $definitions = $this->tests->resultDefinitions($entries->pluck('test_id')->all());
        $departments = $this->tests->departments($entries->pluck('department_id')->all());
        $samples = $this->samples->many($report->organization_id, $entries->pluck('sample_id')->all());
        $results = LabResult::query()
            ->whereIn('worklist_entry_id', $entries->pluck('id'))
            ->where('is_final', true)
            ->get()
            ->groupBy('worklist_entry_id');
        $signatures = $report->signatures()->with('signatory')->get()->keyBy('department_id');
        $signerNames = $this->staffDirectory->names($signatures->map(fn (ReportSignature $signature) => $signature->signatory->user_id)->values()->all());
        $verifyUrl = url("/api/v1/verify/{$report->qr_code}");

        $sections = [];
        foreach ($departments as $department) {
            $tests = [];

            foreach ($entries->where('department_id', $department->id) as $entry) {
                /** @var WorklistEntry $entry */
                $definition = $definitions[$entry->test_id];
                $byParameter = $results->get($entry->id, collect())->keyBy('test_parameter_id');
                $rows = [];

                foreach ($definition->parameters as $parameter) {
                    $result = $byParameter->get($parameter->id);

                    if ($result === null) {
                        continue;
                    }

                    $rows[] = [
                        'name' => $parameter->name,
                        'value' => $result->value,
                        'flag' => $result->flag?->printedMark() ?? '',
                        'is_abnormal' => $result->flag !== null && $result->flag->printedMark() !== '',
                        'unit' => $result->unit,
                        'range' => $result->ref_range_text,
                        'comment' => $result->comment,
                    ];
                }

                $tests[] = ['name' => $definition->name, 'method' => $definition->method, 'rows' => $rows];
            }

            $signature = $signatures->get($department->id);
            $sections[] = [
                'department' => $department->name,
                'tests' => $tests,
                'signature' => $signature === null ? null : [
                    'name' => $signerNames[$signature->signatory->user_id] ?? '',
                    'qualification' => $signature->signatory->qualification,
                    'registration' => "{$signature->signatory->council_name} Reg. No. {$signature->signatory->registration_no}",
                    'image' => 'data:image/png;base64,'.base64_encode($this->files->contents($signature->signatory->signature_image_path)),
                    'signed_at' => $this->local($signature->signed_at),
                ],
            ];
        }

        $collectedAt = collect($samples)->map(fn ($sample) => $sample->collectedAt)->filter()->min();
        $receivedAt = collect($samples)->map(fn ($sample) => $sample->receivedAt)->filter()->max();
        $lab = $this->network->letterhead($report->processing_branch_id);

        return [
            'brand' => $this->network->organizationName($report->organization_id),
            'lab' => $lab,
            'collection_centre' => $this->network->letterhead($order->branchId),
            'order' => $order,
            'report' => [
                'version' => $report->version,
                'is_partial' => $report->is_partial,
                'amendment_reason' => $report->amendment_reason,
                'released_at' => $this->local($report->released_at),
            ],
            'collected_at' => $this->local($collectedAt),
            'received_at' => $this->local($receivedAt),
            'sections' => $sections,
            'verify_url' => $verifyUrl,
            'qr_svg' => $this->qrCodes->svg($verifyUrl),
        ];
    }

    private function local(?CarbonImmutable $moment): ?string
    {
        return $moment?->setTimezone(self::TIMEZONE)->format('d M Y, h:i A');
    }
}
