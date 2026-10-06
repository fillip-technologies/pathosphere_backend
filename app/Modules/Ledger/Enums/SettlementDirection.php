<?php

namespace App\Modules\Ledger\Enums;

enum SettlementDirection: string
{
    case PartnerPaysHq = 'partner_pays_hq';
    case HqPaysPartner = 'hq_pays_partner';
    case Nil = 'nil';
}
