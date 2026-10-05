<?php

namespace App\Modules\Shared\Audit;

use App\Modules\Shared\Context\CurrentActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use LogicException;

/**
 * Writes one audit_logs row per change. Only changed fields are stored
 * (spec §7.10), and sensitive fields are never stored at all.
 */
final class AuditLogger
{
    /** Columns never copied into the audit trail. */
    private const EXCLUDED_FIELDS = [
        'password_hash', 'mfa_secret', 'bank_account_no', 'refresh_token_hash',
        'code_hash', 'token_hash', 'access_token_hash', 'updated_at', 'updated_by',
    ];

    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly ?Request $request = null,
    ) {}

    /** Record a newly created row with all its (non-sensitive) values. */
    public function recordCreated(string $action, Model $entity): AuditLog
    {
        return $this->record($action, $entity, [], $this->withoutExcludedFields($entity->attributesToArray()));
    }

    /**
     * Record what changed on a model in its last save(). Call it right after
     * saving, inside the same transaction.
     */
    public function recordChanges(string $action, Model $entity): AuditLog
    {
        $newValues = $this->withoutExcludedFields($entity->getChanges());
        $oldValues = array_intersect_key($entity->getPrevious(), $newValues);

        return $this->record($action, $entity, $oldValues, $newValues);
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function record(string $action, Model $entity, array $oldValues = [], array $newValues = []): AuditLog
    {
        return AuditLog::query()->create([
            'organization_id' => $this->organizationIdFor($entity),
            'user_id' => $this->currentActor->userId(),
            'franchise_id' => $this->columnValue($entity, 'franchise_id'),
            'branch_id' => $this->columnValue($entity, 'branch_id'),
            'action' => $action,
            'entity_type' => $entity->getTable(),
            'entity_id' => (string) $entity->getKey(),
            'old_value' => $oldValues === [] ? null : $oldValues,
            'new_value' => $newValues === [] ? null : $newValues,
            'ip_address' => $this->request?->ip(),
            'request_id' => Context::get('request_id'),
        ]);
    }

    /**
     * A change to a branch's configuration owned by another module (e.g. the
     * tests a lab can run), recorded against the branch.
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function recordForBranch(string $action, string $branchId, array $oldValues = [], array $newValues = []): AuditLog
    {
        return AuditLog::query()->create([
            'organization_id' => $this->currentActor->organizationId()
                ?? throw new LogicException("Cannot audit {$action}: the current actor has no organization."),
            'user_id' => $this->currentActor->userId(),
            'franchise_id' => null,
            'branch_id' => $branchId,
            'action' => $action,
            'entity_type' => 'branches',
            'entity_id' => $branchId,
            'old_value' => $oldValues === [] ? null : $oldValues,
            'new_value' => $newValues === [] ? null : $newValues,
            'ip_address' => $this->request?->ip(),
            'request_id' => Context::get('request_id'),
        ]);
    }

    private function organizationIdFor(Model $entity): string
    {
        $organizationId = $this->columnValue($entity, 'organization_id') ?? $this->currentActor->organizationId();

        if ($organizationId === null) {
            throw new LogicException(sprintf(
                'Cannot audit %s %s: no organization on the entity or the current actor.',
                $entity->getTable(),
                $entity->getKey(),
            ));
        }

        return (string) $organizationId;
    }

    /** Reads a scope column only if this model has it (not every table does). */
    private function columnValue(Model $entity, string $column): ?string
    {
        $value = $entity->getAttributes()[$column] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutExcludedFields(array $values): array
    {
        return array_diff_key($values, array_flip(self::EXCLUDED_FIELDS));
    }
}
