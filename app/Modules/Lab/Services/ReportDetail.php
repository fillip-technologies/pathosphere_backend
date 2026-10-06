<?php

namespace App\Modules\Lab\Services;

use App\Modules\Catalogue\Services\DepartmentFacts;
use App\Modules\Lab\Models\Report;
use App\Modules\Lab\Models\ReportSignature;

/** A report with its departments, the tests in each and who signed them. */
final class ReportDetail
{
    /**
     * @param  list<array{department: DepartmentFacts, tests: list<array{order_item_id: string, test_code: string, test_name: string, status: string}>, signature: ReportSignature|null}>  $sections
     */
    public function __construct(
        public readonly Report $report,
        public readonly array $sections,
    ) {}
}
