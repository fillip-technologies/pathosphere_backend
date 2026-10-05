<?php

namespace App\Modules\Shared\StateMachines;

use App\Modules\Shared\Audit\AuditLogger;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only code allowed to change a status column (spec §5). Each entity's
 * machine lists its allowed transitions; every transition is checked, saved
 * and audited in one transaction.
 *
 * Subclasses add the business guards and side effects for their entity, e.g.
 * "a suspended franchise cannot create orders", in afterTransition().
 */
abstract class StateMachine
{
    /** Use as a "from" key for transitions allowed from any status. */
    protected const ANY = '*';

    public function __construct(protected readonly AuditLogger $auditLogger) {}

    /**
     * Allowed moves as from-value => list of to-values, e.g.
     * ['draft' => ['confirmed', 'cancelled'], self::ANY => ['terminated']].
     *
     * @return array<string, list<string>>
     */
    abstract protected function transitions(): array;

    /** Singular entity name used in audit actions and errors, e.g. "order". */
    abstract protected function entityName(): string;

    protected function statusColumn(): string
    {
        return 'status';
    }

    /** Hook for side effects that must happen in the same transaction. */
    protected function afterTransition(Model $entity, string $from, BackedEnum $to): void {}

    public function canTransition(string|BackedEnum $from, string|BackedEnum $to): bool
    {
        $fromValue = $from instanceof BackedEnum ? (string) $from->value : $from;
        $toValue = $to instanceof BackedEnum ? (string) $to->value : $to;
        $transitions = $this->transitions();

        return in_array($toValue, $transitions[$fromValue] ?? [], true)
            || in_array($toValue, $transitions[self::ANY] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $attributes  other columns that change with the status, e.g. cancelled_reason
     */
    public function transition(Model $entity, BackedEnum $to, array $attributes = []): Model
    {
        $from = $this->currentStatus($entity);

        if ($from === (string) $to->value || ! $this->canTransition($from, $to)) {
            throw new InvalidStatusTransition($this->entityName(), $from, (string) $to->value);
        }

        return DB::transaction(function () use ($entity, $from, $to, $attributes): Model {
            $entity->forceFill([$this->statusColumn() => $to] + $attributes)->save();
            $this->auditLogger->recordChanges("{$this->entityName()}.status_changed", $entity);
            $this->afterTransition($entity, $from, $to);

            return $entity;
        });
    }

    private function currentStatus(Model $entity): string
    {
        $status = $entity->getAttribute($this->statusColumn());

        return $status instanceof BackedEnum ? (string) $status->value : (string) $status;
    }
}
