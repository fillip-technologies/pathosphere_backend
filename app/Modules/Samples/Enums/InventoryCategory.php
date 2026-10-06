<?php

namespace App\Modules\Samples\Enums;

enum InventoryCategory: string
{
    case Reagent = 'reagent';
    case Consumable = 'consumable';
    case Tube = 'tube';
    case Kit = 'kit';
    case Control = 'control';
}
