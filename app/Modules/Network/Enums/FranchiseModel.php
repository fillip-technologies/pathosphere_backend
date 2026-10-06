<?php

namespace App\Modules\Network\Enums;

/** What the franchise runs: collection only, or its own lab too (spec §5.1 step 3). */
enum FranchiseModel: string
{
    case Psc = 'psc';
    case Lab = 'lab';
}
