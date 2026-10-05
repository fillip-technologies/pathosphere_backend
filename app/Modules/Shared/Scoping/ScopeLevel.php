<?php

namespace App\Modules\Shared\Scoping;

/**
 * How much of the network a role can see (spec §4, roles.scope_level).
 */
enum ScopeLevel: string
{
    case Organization = 'organization';
    case Region = 'region';
    case Franchise = 'franchise';
    case Branch = 'branch';
    case B2bClient = 'b2b_client';
}
