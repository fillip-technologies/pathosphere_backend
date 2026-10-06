<?php

namespace App\Modules\Locker\Enums;

/** How a family member relates to the account holder. */
enum FamilyRelation: string
{
    case Spouse = 'spouse';
    case Child = 'child';
    case Parent = 'parent';
    case Sibling = 'sibling';
    case Grandparent = 'grandparent';
    case Grandchild = 'grandchild';
    case Other = 'other';
}
