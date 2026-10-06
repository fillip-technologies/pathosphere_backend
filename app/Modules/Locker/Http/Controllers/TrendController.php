<?php

namespace App\Modules\Locker\Http\Controllers;

use App\Modules\Locker\Errors\LockerError;
use App\Modules\Locker\Http\Resources\TrendResource;
use App\Modules\Locker\Services\HealthTrends;
use App\Modules\Locker\Services\PatientViewer;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Results over time per parameter code, across every branch and lab (spec §12 Phase 7). */
final class TrendController
{
    public function __construct(
        private readonly HealthTrends $trends,
        private readonly PatientViewer $viewer,
    ) {}

    /**
     * GET /me/trends: every parameter ever reported, with its latest value.
     * A patient has at most a few hundred, so this is one page.
     */
    public function index(): Response
    {
        $series = $this->trends->all($this->viewer);

        return new JsonResponse([
            'data' => array_map(fn ($one) => TrendResource::make($one, false)->resolve(), $series),
            'pagination' => ['next_cursor' => null, 'limit' => count($series)],
        ]);
    }

    /** GET /me/trends/{parameter_code}: every value, oldest first. */
    public function show(string $parameterCode): Response
    {
        $series = $this->trends->forParameter($this->viewer, $parameterCode);

        if ($series->points === []) {
            throw LockerError::notFound('parameter');
        }

        return TrendResource::make($series, true)->response();
    }
}
