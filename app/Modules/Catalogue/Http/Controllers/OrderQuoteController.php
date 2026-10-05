<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Modules\Catalogue\Errors\CatalogueError;
use App\Modules\Catalogue\Http\Requests\OrderQuoteRequest;
use App\Modules\Catalogue\Http\Resources\QuotePresenter;
use App\Modules\Catalogue\Services\OrderQuoteService;
use Illuminate\Http\JsonResponse;

/**
 * POST /order-quotes: prices, routing and (from Phase 6) the wallet check for
 * a booking, without saving anything. A noun resource per decision D4.
 */
final class OrderQuoteController
{
    public function __invoke(OrderQuoteRequest $request, OrderQuoteService $quotes): JsonResponse
    {
        $b2bClientId = $request->validated('b2b_client_id');
        $result = $quotes->quote($request->validated('branch_id'), $b2bClientId, $request->quoteItems());
        $quote = $result['quote'];

        if (! $quote->isBookable()) {
            throw CatalogueError::unbookable($quote);
        }

        return new JsonResponse(['data' => QuotePresenter::present(
            $quote,
            $result['branch']->branchId,
            $b2bClientId,
            $result['mrp_price_list_id'],
            $result['partner_price_list_id'],
        )]);
    }
}
