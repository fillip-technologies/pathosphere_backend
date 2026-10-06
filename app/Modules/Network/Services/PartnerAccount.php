<?php

namespace App\Modules\Network\Services;

use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Enums\SettlementCycle;
use App\Modules\Shared\Money\Money;

/** A franchise or B2B client as the ledger sees it: its balance, limits and terms. */
final class PartnerAccount
{
    public function __construct(
        public readonly PartnerType $type,
        public readonly string $id,
        public readonly string $organizationId,
        public readonly string $code,
        public readonly string $name,
        public readonly Money $balance,
        public readonly Money $creditLimit,
        /** Agreement in force; null for B2B clients and franchises without one. */
        public readonly ?BillingTerms $terms,
        public readonly bool $canTrade,
        public readonly ?string $phone,
        public readonly ?string $email,
    ) {}

    public function isFranchise(): bool
    {
        return $this->type === PartnerType::Franchise;
    }

    /** B2B clients settle monthly; franchises on their agreement's cycle. */
    public function settlementCycle(): SettlementCycle
    {
        return $this->terms->settlementCycle ?? SettlementCycle::Monthly;
    }
}
