<?php

namespace App\Modules\Locker\Services;

use App\Modules\Booking\Enums\AbdmRequestStatus;
use App\Modules\Booking\Services\AbdmRequestLog;
use App\Modules\Locker\Contracts\Abdm\CareContextsLinked;
use App\Modules\Locker\Contracts\Abdm\ConsentNotified;
use App\Modules\Locker\Contracts\Abdm\DiscoveryRequested;
use App\Modules\Locker\Contracts\Abdm\HealthInformationRequested;
use App\Modules\Locker\Contracts\Abdm\HipCallback;
use App\Modules\Locker\Contracts\Abdm\LinkConfirmed;
use App\Modules\Locker\Contracts\Abdm\LinkRequested;
use App\Modules\Locker\Contracts\Abdm\LinkTokenIssued;
use App\Modules\Locker\Models\AbdmCareContext;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Jobs\WithSystemScope;
use LogicException;

/** Hands each ABDM callback to the service that answers it and records the outcome. */
final class HipCallbackHandler
{
    public function __construct(
        private readonly CareContextLinker $linker,
        private readonly UserInitiatedLinking $linking,
        private readonly AbdmConsentMirror $consents,
        private readonly HealthInformationTransfers $transfers,
        private readonly AbdmRequestLog $requestLog,
        private readonly NetworkDirectory $network,
    ) {}

    public function handle(HipCallback $callback): void
    {
        // Audit and scoped reads need the organization the facility or our earlier request belongs to.
        WithSystemScope::run($this->organizationOf($callback), fn () => $this->dispatch($callback));
    }

    private function dispatch(HipCallback $callback): void
    {
        $error = match (true) {
            $callback instanceof LinkTokenIssued => $this->settled(fn () => $this->linker->linkTokenIssued($callback)),
            $callback instanceof CareContextsLinked => $this->settled(fn () => $this->linker->careContextsLinked($callback)),
            $callback instanceof DiscoveryRequested => $this->linking->discover($callback),
            $callback instanceof LinkRequested => $this->linking->requestLink($callback),
            $callback instanceof LinkConfirmed => $this->linking->confirmLink($callback),
            $callback instanceof ConsentNotified => $this->consents->notified($callback),
            $callback instanceof HealthInformationRequested => $this->transfers->requested($callback),
            default => throw new LogicException('Unhandled ABDM callback '.$callback::class),
        };

        $this->requestLog->settle(
            $callback->requestId(),
            $error === null ? AbdmRequestStatus::Success : AbdmRequestStatus::Failed,
            $error === null ? null : mb_substr($error->code, 0, 50),
        );
    }

    private function organizationOf(HipCallback $callback): ?string
    {
        $branchId = match (true) {
            $callback instanceof LinkTokenIssued => $this->requestLog->outboundRequest($callback->respondingToRequestId)?->branchId,
            $callback instanceof CareContextsLinked => AbdmCareContext::query()->where('link_request_id', $callback->respondingToRequestId)->value('hip_branch_id'),
            $callback instanceof DiscoveryRequested,
            $callback instanceof LinkRequested,
            $callback instanceof LinkConfirmed,
            $callback instanceof ConsentNotified,
            $callback instanceof HealthInformationRequested => $this->network->branchIdByHfrId($callback->hipId),
            default => null,
        };

        return $branchId === null ? null : $this->network->branchContact((string) $branchId)->organizationId;
    }

    /** For answers to our own requests: their outcome is recorded on our request. */
    private function settled(callable $action): null
    {
        $action();

        return null;
    }
}
