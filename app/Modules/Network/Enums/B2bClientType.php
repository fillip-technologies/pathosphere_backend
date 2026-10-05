<?php

namespace App\Modules\Network\Enums;

enum B2bClientType: string
{
    case Hospital = 'hospital';
    case NursingHome = 'nursing_home';
    case Clinic = 'clinic';
    case Lab = 'lab';
    case Corporate = 'corporate';
}
