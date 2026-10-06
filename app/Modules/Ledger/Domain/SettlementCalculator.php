<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SettlementDirection;
use App\Modules\Shared\Money\Money;

/**
 * Settlement maths (spec §5.6 step 3), pure.
 *
 * Shares describe the period: for a revenue-share franchise the partner's
 * share is its commission; for a wholesale franchise HQ's share is what it
 * charged for tests (net of reversals) and the rest of patient billing is the
 * franchise's margin; for a B2B client everything billed is HQ's.
 *
 * The amount to settle comes from the account balance at the period end: a
 * negative balance is owed to HQ; a positive one is paid out only to a
 * revenue-share franchise (earned commission). A prepaid wallet or a B2B
 * advance stays in the account for later charges.
 *
 * Tax is zero until the CA confirms whether GST applies to royalty and fees
 * (spec §7.8 "if applicable").
 */
final class SettlementCalculator
{
    /** @param  list<LedgerLine>  $lines  the rows this settlement covers */
    public static function calculate(PartnerModel $model, Money $patientBilling, array $lines, Money $closingBalance): SettlementFigures
    {
        [$grossBilling, $partnerShare, $hqShare] = match ($model) {
            PartnerModel::RevenueShare => self::revenueShareSplit($patientBilling, $lines),
            PartnerModel::Wholesale => self::wholesaleSplit($patientBilling, $lines),
            PartnerModel::B2bClient => self::clientSplit($lines),
        };

        [$direction, $netAmount] = match (true) {
            $closingBalance->isNegative() => [SettlementDirection::PartnerPaysHq, Money::zero()->subtract($closingBalance)],
            $closingBalance->isGreaterThan(Money::zero()) && $model->paysOutCredit() => [SettlementDirection::HqPaysPartner, $closingBalance],
            default => [SettlementDirection::Nil, Money::zero()],
        };

        return new SettlementFigures($grossBilling, $partnerShare, $hqShare, Money::zero(), $netAmount, $direction);
    }

    /**
     * @param  list<LedgerLine>  $lines
     * @return array{Money, Money, Money}
     */
    private static function revenueShareSplit(Money $patientBilling, array $lines): array
    {
        $commission = self::net($lines, [LedgerEntryType::Commission], creditPositive: true);

        return [$patientBilling, $commission, $patientBilling->subtract($commission)];
    }

    /**
     * @param  list<LedgerLine>  $lines
     * @return array{Money, Money, Money}
     */
    private static function wholesaleSplit(Money $patientBilling, array $lines): array
    {
        $testCharges = self::net($lines, [LedgerEntryType::PartnerCharge, LedgerEntryType::RefundReversal], creditPositive: false);

        return [$patientBilling, $patientBilling->subtract($testCharges), $testCharges];
    }

    /**
     * @param  list<LedgerLine>  $lines
     * @return array{Money, Money, Money}
     */
    private static function clientSplit(array $lines): array
    {
        $billed = self::net($lines, [LedgerEntryType::PartnerCharge, LedgerEntryType::RefundReversal], creditPositive: false);

        return [$billed, Money::zero(), $billed];
    }

    /**
     * @param  list<LedgerLine>  $lines
     * @param  list<LedgerEntryType>  $types
     */
    private static function net(array $lines, array $types, bool $creditPositive): Money
    {
        $total = Money::zero();

        foreach ($lines as $line) {
            if (in_array($line->entryType, $types, true)) {
                $total = $total->add($line->credit)->subtract($line->debit);
            }
        }

        return $creditPositive ? $total : Money::zero()->subtract($total);
    }
}
