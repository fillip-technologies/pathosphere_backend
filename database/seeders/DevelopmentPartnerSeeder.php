<?php

namespace Database\Seeders;

use App\Modules\Ledger\Domain\PostingRules;
use App\Modules\Ledger\Services\LedgerPostingService;
use App\Modules\Network\Enums\AgreementStatus;
use App\Modules\Network\Enums\BillingModel;
use App\Modules\Network\Enums\FranchiseModel;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Enums\SettlementCycle;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\FranchiseAgreement;
use App\Modules\Network\Models\TerritoryPincode;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo franchise agreements from spec §11.6, one per billing model:
 * Gaya Diagnostics buys tests wholesale from a prepaid wallet, Dhanbad
 * Health Point shares revenue at 30%. Both signed, with the fee and deposit
 * posted to the ledger as a real signature would (they cancel out, so each
 * wallet starts at zero and Gaya must top up before booking).
 *
 * Runs after DevelopmentCatalogueSeeder; local and testing only.
 */
class DevelopmentPartnerSeeder extends Seeder
{
    public function run(LedgerPostingService $postings): void
    {
        if (FranchiseAgreement::query()->exists()) {
            return;
        }

        $this->agreement($postings, 'FRGAYA', BillingModel::Wholesale, null, ['823001', '824231']);
        $this->agreement($postings, 'FRDHN', BillingModel::RevenueShare, '30.00', ['826001']);
    }

    /** @param  list<string>  $pincodes */
    private function agreement(LedgerPostingService $postings, string $franchiseCode, BillingModel $billingModel, ?string $commissionPct, array $pincodes): void
    {
        $franchise = Franchise::query()->where('franchise_code', $franchiseCode)->firstOrFail();
        $start = CarbonImmutable::parse('2026-04-01');

        $agreement = new FranchiseAgreement([
            'franchise_model' => FranchiseModel::Psc,
            'billing_model' => $billingModel,
            'commission_pct' => $commissionPct,
            'franchise_fee' => '25000.00',
            'security_deposit' => '25000.00',
            'territory' => 'Exclusive collection territory',
            'settlement_cycle' => SettlementCycle::Monthly,
            'start_date' => $start->toDateString(),
            'end_date' => $start->addYears(3)->subDay()->toDateString(),
            'status' => AgreementStatus::Active,
            'signed_at' => $start,
        ]);
        $agreement->organization_id = $franchise->organization_id;
        $agreement->franchise_id = $franchise->id;
        $agreement->agreement_no = "AGR-DEMO-{$franchiseCode}";
        $agreement->save();

        foreach ($pincodes as $pincode) {
            $row = new TerritoryPincode(['pincode' => $pincode]);
            $row->agreement_id = $agreement->id;
            $row->save();
        }

        $franchise->forceFill(['onboarded_at' => $start])->save();

        $postings->post($franchise->organization_id, PartnerType::Franchise, $franchise->id, PostingRules::agreementSigned(
            $agreement->id,
            $agreement->agreement_no,
            Money::fromString('25000.00'),
            Money::fromString('25000.00'),
        ));
    }
}
