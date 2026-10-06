<?php

namespace App\Modules\Network\Services;

use App\Modules\Network\Enums\AgreementStatus;
use App\Modules\Network\Enums\B2bClientStatus;
use App\Modules\Network\Enums\FranchiseStatus;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Models\B2bClient;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\FranchiseAgreement;
use App\Modules\Network\StateMachines\B2bClientStateMachine;
use App\Modules\Network\StateMachines\FranchiseStateMachine;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Partner accounts for the ledger (spec §5.6, §7.8). `current_balance` lives
 * on the franchise and B2B client rows; only the ledger's posting service
 * changes it, under the row lock taken here.
 *
 * Postings are internal bookkeeping for an action the caller was already
 * allowed to take (e.g. a franchise desk confirming an order), so accounts are
 * read at organization level: a branch user cannot see the franchise row.
 */
final class PartnerAccounts
{
    public function __construct(
        private readonly CurrentScope $currentScope,
        private readonly FranchiseStateMachine $franchiseStates,
        private readonly B2bClientStateMachine $clientStates,
    ) {}

    /** Locks the partner row for a posting (SELECT … FOR UPDATE). Call inside the posting transaction. */
    public function lockForPosting(string $organizationId, PartnerType $type, string $partnerId): PartnerAccount
    {
        return $this->asOrganization($organizationId, fn () => $this->toAccount(
            $this->query($type)->lockForUpdate()->findOrFail($partnerId),
        ));
    }

    public function recordBalance(string $organizationId, PartnerType $type, string $partnerId, Money $balance): void
    {
        $this->asOrganization($organizationId, fn () => $this->query($type)->whereKey($partnerId)->update(['current_balance' => $balance->toDecimalString()]));
    }

    public function find(string $organizationId, PartnerType $type, string $partnerId): ?PartnerAccount
    {
        return $this->asOrganization($organizationId, function () use ($type, $partnerId): ?PartnerAccount {
            $partner = $this->query($type)->find($partnerId);

            return $partner === null ? null : $this->toAccount($partner);
        });
    }

    /**
     * Partners that may need a settlement: franchises that ever had an
     * agreement in force, and every B2B client.
     *
     * @return list<PartnerAccount>
     */
    public function settlingPartners(string $organizationId): array
    {
        return $this->asOrganization($organizationId, function (): array {
            $franchises = Franchise::query()
                ->whereHas('agreements', fn ($agreements) => $agreements->whereIn('status', [AgreementStatus::Active, AgreementStatus::Expired, AgreementStatus::Terminated]))
                ->get();

            return [
                ...$franchises->map(fn (Franchise $franchise) => $this->toAccount($franchise))->all(),
                ...B2bClient::query()->get()->map(fn (B2bClient $client) => $this->toAccount($client))->all(),
            ];
        });
    }

    /**
     * Auto-hold (spec §9, off by default): a partner past its credit limit
     * for too long stops taking new orders until HQ Finance clears it.
     *
     * @return bool whether the partner was put on hold now
     */
    public function holdForOverdueDues(string $organizationId, PartnerType $type, string $partnerId): bool
    {
        return $this->asOrganization($organizationId, function () use ($type, $partnerId): bool {
            if ($type === PartnerType::Franchise) {
                $franchise = Franchise::query()->findOrFail($partnerId);

                if ($franchise->status !== FranchiseStatus::Active) {
                    return false;
                }

                $this->franchiseStates->transition($franchise, FranchiseStatus::Suspended);

                return true;
            }

            $client = B2bClient::query()->findOrFail($partnerId);

            if ($client->status !== B2bClientStatus::Active) {
                return false;
            }

            $this->clientStates->transition($client, B2bClientStatus::OnHold);

            return true;
        });
    }

    /** @return Builder<Franchise>|Builder<B2bClient> */
    private function query(PartnerType $type): Builder
    {
        return match ($type) {
            PartnerType::Franchise => Franchise::query(),
            PartnerType::B2bClient => B2bClient::query(),
        };
    }

    private function toAccount(Franchise|B2bClient $partner): PartnerAccount
    {
        if ($partner instanceof B2bClient) {
            return new PartnerAccount(
                PartnerType::B2bClient,
                $partner->id,
                $partner->organization_id,
                $partner->client_code,
                $partner->name,
                $partner->current_balance,
                $partner->credit_limit,
                null,
                $partner->status === B2bClientStatus::Active,
                $partner->phone,
                $partner->email,
            );
        }

        return new PartnerAccount(
            PartnerType::Franchise,
            $partner->id,
            $partner->organization_id,
            $partner->franchise_code,
            $partner->name,
            $partner->current_balance,
            $partner->credit_limit,
            $this->termsOf($partner->id),
            $partner->status === FranchiseStatus::Active,
            $partner->phone,
            $partner->email,
        );
    }

    /**
     * The agreement in force, or the latest one that ended, so a settlement
     * after expiry still follows the terms the money was earned under.
     */
    private function termsOf(string $franchiseId): ?BillingTerms
    {
        $agreement = FranchiseAgreement::query()
            ->where('franchise_id', $franchiseId)
            ->whereIn('status', [AgreementStatus::Active, AgreementStatus::Expired, AgreementStatus::Terminated])
            ->orderByRaw("`status` = 'active' desc")
            ->orderByDesc('signed_at')
            ->first();

        if ($agreement === null) {
            return null;
        }

        return new BillingTerms(
            $agreement->id,
            $agreement->agreement_no,
            $agreement->billing_model,
            $agreement->commission_pct,
            $agreement->settlement_cycle,
            $agreement->start_date,
            $agreement->status === AgreementStatus::Active,
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asOrganization(string $organizationId, callable $callback): mixed
    {
        if ($organizationId === '') {
            throw new LogicException('Partner accounts are always read within one organization.');
        }

        return $this->currentScope->runAs(ScopeContext::system($organizationId), $callback);
    }
}
