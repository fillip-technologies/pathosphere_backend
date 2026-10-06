<?php

namespace App\Modules\Lab\Http\Controllers;

use App\Modules\Lab\Services\PublicReportVerification;
use App\Modules\Lab\Services\ReportFiles;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** The two ways to reach a report without signing in: its QR code and a signed link. */
final class PublicReportController
{
    public function __construct(private readonly PublicReportVerification $verification) {}

    /** GET /verify/{qr_code} (rate-limited): is this report genuine? */
    public function verify(string $qrCode): Response
    {
        $report = $this->verification->lookup($qrCode) ?? abort(404);

        return new JsonResponse(['data' => $report]);
    }

    /** GET /report-links/{report}?expires&signature: the PDF behind a link sent to the patient or doctor. */
    public function download(string $reportId, ReportFiles $files): Response
    {
        $report = $this->verification->releasedReport($reportId) ?? abort(404);

        return $files->download($report, 'signed_link');
    }
}
