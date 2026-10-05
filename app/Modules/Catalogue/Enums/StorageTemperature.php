<?php

namespace App\Modules\Catalogue\Enums;

enum StorageTemperature: string
{
    case Ambient = 'ambient';
    case Refrigerated = '2_8c';
    case Frozen = 'frozen';
}
