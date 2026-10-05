<?php

namespace App\Modules\Booking\Domain;

use App\Modules\Catalogue\Domain\Quote;
use App\Modules\Catalogue\Domain\QuoteLine;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Turns a bookable quote into order lines: discount spread over the priced
 * lines, package children at price 0, and a promised report time per test
 * from its TAT (spec §7.5 due_at). The promise is refined at sample receipt.
 */
final class OrderLinePlanner
{
    /** @return list<OrderLineDraft> */
    public static function plan(Quote $quote, Money $discount, CarbonImmutable $bookedAt): array
    {
        $discounts = DiscountAllocator::allocate($discount, array_map(fn (QuoteLine $line) => $line->mrpPrice, $quote->lines));

        $drafts = [];
        foreach ($quote->lines as $index => $line) {
            $drafts[] = $line->lineType === QuoteLine::PACKAGE
                ? new OrderLineDraft(
                    null,
                    $line->itemId,
                    null,
                    $line->mrpPrice,
                    $line->partnerPrice,
                    $discounts[$index],
                    self::latestDue($line->children, $bookedAt),
                    array_map(fn (QuoteLine $child) => self::testLine($child, Money::zero(), $bookedAt), $line->children),
                )
                : self::testLine($line, $discounts[$index], $bookedAt);
        }

        return $drafts;
    }

    private static function testLine(QuoteLine $line, Money $discount, CarbonImmutable $bookedAt): OrderLineDraft
    {
        return new OrderLineDraft(
            $line->itemId,
            null,
            $line->processingBranchId,
            $line->mrpPrice,
            $line->partnerPrice,
            $discount,
            $bookedAt->addHours((int) $line->tatHours),
        );
    }

    /** @param  list<QuoteLine>  $children */
    private static function latestDue(array $children, CarbonImmutable $bookedAt): CarbonImmutable
    {
        $longestTat = max(array_map(fn (QuoteLine $child) => (int) $child->tatHours, $children) ?: [0]);

        return $bookedAt->addHours($longestTat);
    }
}
