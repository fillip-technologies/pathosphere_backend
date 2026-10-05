<?php

namespace App\Modules\Network\Enums;

enum RegionType: string
{
    case Zone = 'zone';
    case State = 'state';
    case City = 'city';
    case Area = 'area';
}
