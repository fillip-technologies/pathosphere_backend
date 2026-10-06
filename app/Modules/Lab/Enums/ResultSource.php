<?php

namespace App\Modules\Lab\Enums;

/**
 * Where a result came from. `calculated` is an addition to the spec's
 * analyser/manual: derived parameters such as LDL are computed here.
 */
enum ResultSource: string
{
    case Analyser = 'analyser';
    case Manual = 'manual';
    case Calculated = 'calculated';
}
