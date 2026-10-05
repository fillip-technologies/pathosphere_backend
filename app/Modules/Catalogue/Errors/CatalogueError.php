<?php

namespace App\Modules\Catalogue\Errors;

use App\Modules\Catalogue\Domain\Quote;
use App\Modules\Catalogue\Domain\QuoteProblem;
use App\Modules\Shared\Errors\DomainError;

final class CatalogueError
{
    /**
     * A quote with problems. The top-level code is the first problem's code
     * (e.g. PRICE_MISSING, NO_ROUTE_FOR_TEST); every problem is in details.
     */
    public static function unbookable(Quote $quote): DomainError
    {
        $first = $quote->problems[0];

        return new DomainError(
            $first->code,
            count($quote->problems) === 1 ? $first->message : 'Some items cannot be booked. See details for each item.',
            422,
            array_map(fn (QuoteProblem $problem) => $problem->toDetail(), $quote->problems),
        );
    }

    public static function branchNotOperating(): DomainError
    {
        return new DomainError('BRANCH_NOT_OPERATING', 'This branch is not open for bookings.', 422);
    }

    public static function noDefaultMrpList(): DomainError
    {
        return new DomainError('DEFAULT_MRP_LIST_MISSING', 'No default patient price list is in effect. Ask head office to set one.', 422);
    }

    public static function branchNotLab(): DomainError
    {
        return new DomainError('BRANCH_NOT_LAB', 'Only reference and clinical labs can run tests.', 422);
    }

    public static function inUse(string $what): DomainError
    {
        return new DomainError('CATALOGUE_ITEM_IN_USE', "This {$what} is still used and cannot be deleted. Deactivate it instead.", 409);
    }
}
