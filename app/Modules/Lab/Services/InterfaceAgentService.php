<?php

namespace App\Modules\Lab\Services;

use App\Modules\Lab\Errors\LabError;
use App\Modules\Lab\Models\InterfaceAgent;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * API keys for each lab's analyser interface (spec §8.1: per-lab key plus an
 * IP allow-list). The key is shown once at creation; only its hash is kept.
 */
final class InterfaceAgentService
{
    private const KEY_PREFIX = 'pla_';

    public function __construct(
        private readonly CurrentScope $currentScope,
        private readonly NetworkDirectory $network,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  list<string>  $allowedIps  addresses or CIDR ranges
     * @return array{InterfaceAgent, string} the agent and its key, which is never shown again
     */
    public function create(string $organizationId, string $labId, string $name, array $allowedIps): array
    {
        if (! $this->network->labIsVisible($labId)) {
            throw ValidationException::withMessages(['branch_id' => 'Interface agents belong to a lab.']);
        }

        $key = self::KEY_PREFIX.Str::random(40);

        $agent = DB::transaction(function () use ($organizationId, $labId, $name, $allowedIps, $key): InterfaceAgent {
            $agent = new InterfaceAgent(['name' => $name, 'allowed_ips' => $allowedIps]);
            $agent->forceFill([
                'organization_id' => $organizationId,
                'branch_id' => $labId,
                'key_prefix' => substr($key, 0, 12),
                'key_hash' => hash('sha256', $key),
            ])->save();
            $this->auditLogger->recordCreated('interface_agent.create', $agent);

            return $agent;
        });

        return [$agent, $key];
    }

    public function revoke(InterfaceAgent $agent): InterfaceAgent
    {
        return DB::transaction(function () use ($agent): InterfaceAgent {
            $agent->update(['is_active' => false]);
            $this->auditLogger->recordChanges('interface_agent.revoke', $agent);

            return $agent;
        });
    }

    /** The active agent holding this key, calling from an allowed address. */
    public function authenticate(?string $key, ?string $ipAddress): InterfaceAgent
    {
        if ($key === null || ! str_starts_with($key, self::KEY_PREFIX)) {
            throw LabError::agentKeyInvalid();
        }

        $agent = $this->currentScope->runAs(ScopeContext::system(), fn (): ?InterfaceAgent => InterfaceAgent::query()
            ->where('key_hash', hash('sha256', $key))
            ->where('is_active', true)
            ->first());

        if ($agent === null) {
            throw LabError::agentKeyInvalid();
        }

        if ($ipAddress === null || ! IpUtils::checkIp($ipAddress, $agent->allowed_ips)) {
            throw LabError::agentAddressNotAllowed();
        }

        $this->currentScope->runAs(ScopeContext::system(), fn () => $agent->forceFill(['last_seen_at' => CarbonImmutable::now()])->saveQuietly());

        return $agent;
    }
}
