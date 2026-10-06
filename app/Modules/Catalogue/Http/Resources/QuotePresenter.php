<?php

namespace App\Modules\Catalogue\Http\Resources;

use App\Modules\Catalogue\Domain\Quote;
use App\Modules\Catalogue\Domain\QuoteLine;

/** JSON shape of a successful quote. */
final class QuotePresenter
{
    /**
     * @param  array<string, mixed>|null  $walletCheck  for bookings debited from a prepaid franchise wallet
     * @return array<string, mixed>
     */
    public static function present(Quote $quote, string $branchId, ?string $b2bClientId, string $mrpPriceListId, ?string $partnerPriceListId, ?array $walletCheck): array
    {
        return [
            'branch_id' => $branchId,
            'b2b_client_id' => $b2bClientId,
            'mrp_price_list_id' => $mrpPriceListId,
            'partner_price_list_id' => $partnerPriceListId,
            'lines' => array_map(fn (QuoteLine $line) => self::line($line), $quote->lines),
            'totals' => [
                'mrp_total' => $quote->mrpTotal(),
                'partner_total' => $quote->partnerTotal(),
            ],
            'wallet_check' => $walletCheck,
        ];
    }

    /** @return array<string, mixed> */
    private static function line(QuoteLine $line): array
    {
        return [
            'line_type' => $line->lineType,
            'test_id' => $line->lineType === QuoteLine::TEST ? $line->itemId : null,
            'package_id' => $line->lineType === QuoteLine::PACKAGE ? $line->itemId : null,
            'code' => $line->code,
            'name' => $line->name,
            'mrp_price' => $line->mrpPrice,
            'partner_price' => $line->partnerPrice,
            'processing_branch_id' => $line->processingBranchId,
            'tat_hours' => $line->tatHours,
            'sample_type' => $line->sampleType,
            'container_type' => $line->containerType,
            'children' => array_map(fn (QuoteLine $child) => self::line($child), $line->children),
        ];
    }
}
