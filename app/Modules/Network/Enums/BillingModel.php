<?php

namespace App\Modules\Network\Enums;

/** How a franchise pays HQ (spec glossary). */
enum BillingModel: string
{
    /** Franchise earns commission_pct of what it bills; HQ keeps the rest. */
    case RevenueShare = 'revenue_share';

    /** Franchise buys each test at partner price from a prepaid wallet. */
    case Wholesale = 'wholesale';
}
