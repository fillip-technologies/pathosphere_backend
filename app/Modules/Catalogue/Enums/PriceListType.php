<?php

namespace App\Modules\Catalogue\Enums;

/**
 * mrp: what patients pay. partner: what HQ charges a franchise.
 * client: what HQ charges a B2B client (spec §7.4).
 */
enum PriceListType: string
{
    case Mrp = 'mrp';
    case Partner = 'partner';
    case Client = 'client';
}
