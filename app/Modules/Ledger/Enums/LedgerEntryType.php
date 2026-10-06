<?php

namespace App\Modules\Ledger\Enums;

/** What a partner ledger row records (spec §5.6, §7.8). */
enum LedgerEntryType: string
{
    case WalletTopup = 'wallet_topup';
    case PartnerCharge = 'partner_charge';
    case Commission = 'commission';
    case FranchiseFee = 'franchise_fee';
    case SecurityDeposit = 'security_deposit';
    case KitSupply = 'kit_supply';
    case RefundReversal = 'refund_reversal';
    case PaymentReceived = 'payment_received';
    case Payout = 'payout';
    case Adjustment = 'adjustment';
}
