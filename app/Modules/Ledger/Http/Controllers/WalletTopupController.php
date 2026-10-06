<?php

namespace App\Modules\Ledger\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Ledger\Http\Requests\WalletTopupRequest;
use App\Modules\Ledger\Http\Resources\WalletTopupResource;
use App\Modules\Ledger\Models\WalletTopup;
use App\Modules\Ledger\Services\WalletTopupService;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Scoping\CurrentScope;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** POST /wallet/topups returns a payment link; the credit follows the gateway webhook (spec §8 Ledger). */
final class WalletTopupController
{
    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['franchise_id' => 'franchise_id', 'status' => 'status'])
            ->allowSorts(['created_at'])
            ->apply(WalletTopup::query());

        return CursorPage::respond($query, $request, WalletTopupResource::class);
    }

    public function show(WalletTopup $walletTopup): Response
    {
        return WalletTopupResource::make($walletTopup)->response();
    }

    public function store(WalletTopupRequest $request, StaffContext $staff, CurrentScope $currentScope, NetworkDirectory $network, WalletTopupService $topups): Response
    {
        // A franchise user tops up its own wallet; anyone above names the franchise.
        $franchiseId = $currentScope->get()?->franchiseId() ?? $request->validated('franchise_id');

        if ($franchiseId === null || ! $network->franchiseIsVisible($franchiseId)) {
            throw ValidationException::withMessages(['franchise_id' => 'Choose the franchise whose wallet to top up.']);
        }

        [$topup, $link] = $topups->request($staff->user()->organization_id, $franchiseId, Money::fromString((string) $request->validated('amount')));

        return ApiResponse::created((new WalletTopupResource($topup))->withLink($link), "/api/v1/wallet/topups/{$topup->id}");
    }
}
