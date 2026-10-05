<?php

namespace App\Modules\Catalogue\Enums;

enum ResultType: string
{
    case Numeric = 'numeric';
    case Text = 'text';
    case Option = 'option';
    case Calculated = 'calculated';
}
