<?php

namespace App\Modules\Lab\Http\Middleware;

use App\Modules\Lab\Services\InterfaceAgentService;
use App\Modules\Shared\Context\Actor;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * The analyser interface signs in with its lab's key, sent as a bearer
 * token, from an allowed address. It then works with that lab's scope and
 * acts as the system: analyser results have no `entered_by` (spec §7.6).
 */
final class AuthenticateInterfaceAgent
{
    public const AGENT_ATTRIBUTE = 'interface_agent';

    public function __construct(
        private readonly InterfaceAgentService $agents,
        private readonly CurrentScope $currentScope,
        private readonly CurrentActor $currentActor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $agent = $this->agents->authenticate($request->bearerToken(), $request->ip());

        $this->currentScope->set(ScopeContext::branch($agent->organization_id, $agent->branch_id));
        $this->currentActor->set(Actor::system($agent->organization_id));
        $request->attributes->set(self::AGENT_ATTRIBUTE, $agent);
        Context::add(['interface_agent_id' => $agent->id, 'branch_id' => $agent->branch_id]);

        return $next($request);
    }
}
