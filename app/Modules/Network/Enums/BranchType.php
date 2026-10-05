<?php

namespace App\Modules\Network\Enums;

enum BranchType: string
{
    case ReferenceLab = 'reference_lab';
    case ClinicalLab = 'clinical_lab';
    case Psc = 'psc';
    case PickupPoint = 'pickup_point';

    /** Labs run tests; PSCs and pick-up points only collect. */
    public function isLab(): bool
    {
        return $this === self::ReferenceLab || $this === self::ClinicalLab;
    }
}
