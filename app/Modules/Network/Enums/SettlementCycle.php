<?php

namespace App\Modules\Network\Enums;

enum SettlementCycle: string
{
    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case Monthly = 'monthly';
}
