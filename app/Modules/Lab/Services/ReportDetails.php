<?php

namespace App\Modules\Lab\Services;

use App\Modules\Catalogue\Services\TestDirectory;
use App\Modules\Lab\Models\Report;
use App\Modules\Lab\Models\WorklistEntry;

/** The department-by-department view of a report for labs and signatories. */
final class ReportDetails
{
    public function __construct(
        private readonly ReportAssembly $reports,
        private readonly TestDirectory $tests,
    ) {}

    public function describe(Report $report): ReportDetail
    {
        $entries = $this->reports->liveEntries($report);
        $definitions = $this->tests->resultDefinitions($entries->pluck('test_id')->all());
        $signatures = $report->signatures()->get()->keyBy('department_id');
        $sections = [];

        foreach ($this->tests->departments($entries->pluck('department_id')->all()) as $department) {
            $sections[] = [
                'department' => $department,
                'tests' => $entries->where('department_id', $department->id)->map(fn (WorklistEntry $entry) => [
                    'order_item_id' => $entry->order_item_id,
                    'test_code' => $definitions[$entry->test_id]->code,
                    'test_name' => $definitions[$entry->test_id]->name,
                    'status' => $entry->status->value,
                ])->values()->all(),
                'signature' => $signatures->get($department->id),
            ];
        }

        return new ReportDetail($report, $sections);
    }
}
