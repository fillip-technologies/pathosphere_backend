<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Modules\Catalogue\Errors\CatalogueError;
use App\Modules\Catalogue\Http\Requests\OrderQuoteRequest;
use App\Modules\Catalogue\Http\Resources\QuotePresenter;
use App\Modules\Catalogue\Services\OrderQuoteService;
use App\Modules\Ledger\Services\WalletChecks;
use Illuminate\Http\JsonResponse;

/**
 * POST /order-quotes: prices, routing and the wallet check for a booking,
 * without saving anything. A noun resource per decision D4.
 */
final class OrderQuoteController
{
    public function __invoke(OrderQuoteRequest $request, OrderQuoteService $quotes, WalletChecks $wallets): JsonResponse
    {
        $b2bClientId = $request->validated('b2b_client_id');
        $result = $quotes->quote($request->validated('branch_id'), $b2bClientId, $request->quoteItems());
        $quote = $result['quote'];

        if (! $quote->isBookable()) {
            throw CatalogueError::unbookable($quote);
        }

        $branch = $result['branch'];
        $walletCheck = $wallets->forBooking($branch->organizationId, $branch->franchiseId, $b2bClientId, $quote->partnerTotal());

        return new JsonResponse(['data' => QuotePresenter::present(
            $quote,
            $branch->branchId,
            $b2bClientId,
            $result['mrp_price_list_id'],
            $result['partner_price_list_id'],
            $walletCheck?->toArray(),
        )]);
    }
}
