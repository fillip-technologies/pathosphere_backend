<?php

namespace App\Modules\Lab\Http\Controllers;

use App\Modules\Catalogue\Services\ParameterDefinition;
use App\Modules\Lab\Enums\WorklistStatus;
use App\Modules\Lab\Http\Middleware\AuthenticateInterfaceAgent;
use App\Modules\Lab\Http\Requests\AgentResultsRequest;
use App\Modules\Lab\Models\InterfaceAgent;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Lab\Services\AnalyserResultIntake;
use App\Modules\Lab\Services\WorklistDetail;
use App\Modules\Lab\Services\WorklistDetails;
use App\Modules\Shared\Http\Pagination\CursorPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API used by each lab's on-premises interface agent (spec §8 Interface
 * agent): the host query for analysers, and result upload.
 */
final class AgentController
{
    /**
     * GET /agent/orders?since=: tests waiting for results at the agent's lab,
     * changed since the given time, with the parameter codes analysers report.
     */
    public function orders(Request $request, WorklistDetails $details): Response
    {
        $since = $request->query('since');

        if ($since !== null && strtotime((string) $since) === false) {
            throw ValidationException::withMessages(['since' => 'Use an ISO-8601 date and time.']);
        }

        $query = WorklistEntry::query()
            ->whereIn('status', [WorklistStatus::Pending, WorklistStatus::Entered])
            ->when($since !== null, fn ($entries) => $entries->where('updated_at', '>=', CarbonImmutable::parse((string) $since)->utc()));

        return CursorPage::respondWith($query, $request, fn ($entries) => array_map(fn (WorklistDetail $detail) => [
            'order_item_id' => $detail->entry->order_item_id,
            'barcode' => $detail->barcode,
            'sample_type' => $detail->sampleType,
            'test_code' => $detail->test->code,
            'run_no' => $detail->entry->current_run,
            'parameter_codes' => array_values(array_map(
                fn (ParameterDefinition $parameter) => $parameter->code,
                array_filter($detail->test->parameters, fn (ParameterDefinition $parameter) => ! $parameter->isCalculated()),
            )),
            'patient' => ['age_years' => $detail->order->ageYears, 'gender' => $detail->order->gender],
            'updated_at' => $detail->entry->updated_at->toIso8601ZuluString(),
        ], $details->describe($entries)));
    }

    /** POST /agent/results: always 200 with what was accepted, unchanged (replays) and rejected. */
    public function results(AgentResultsRequest $request, AnalyserResultIntake $intake): Response
    {
        /** @var InterfaceAgent $agent */
        $agent = $request->attributes->get(AuthenticateInterfaceAgent::AGENT_ATTRIBUTE);

        return new JsonResponse(['data' => $intake->receive($agent, $request->results())]);
    }
}
