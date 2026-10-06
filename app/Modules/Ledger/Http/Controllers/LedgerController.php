<?php

namespace App\Modules\Ledger\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Ledger\Http\Requests\LedgerAdjustmentRequest;
use App\Modules\Ledger\Http\Resources\LedgerEntryResource;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Ledger\Services\LedgerAdjustmentService;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use App\Modules\Shared\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** GET /ledger (a partner's account) and POST /ledger/adjustments (spec §8 Ledger). */
final class LedgerController
{
    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'franchise_id' => 'franchise_id',
                'b2b_client_id' => 'b2b_client_id',
                'entry_type' => 'entry_type',
                'reference_type' => 'reference_type',
                'reference_id' => 'reference_id',
                'from' => fn ($query, string $date) => $query->where('created_at', '>=', $date),
                'to' => fn ($query, string $date) => $query->where('created_at', '<', $date),
            ])
            ->allowSorts(['created_at'])
            ->apply(LedgerEntry::query());

        return CursorPage::respond($query, $request, LedgerEntryResource::class);
    }

    public function show(LedgerEntry $ledgerEntry): Response
    {
        return LedgerEntryResource::make($ledgerEntry)->response();
    }

    public function adjust(LedgerAdjustmentRequest $request, StaffContext $staff, NetworkDirectory $network, LedgerAdjustmentService $adjustments): Response
    {
        $franchiseId = $request->validated('franchise_id');
        [$partnerType, $partnerId, $visible] = $franchiseId !== null
            ? [PartnerType::Franchise, $franchiseId, $network->franchiseIsVisible($franchiseId)]
            : [PartnerType::B2bClient, (string) $request->validated('b2b_client_id'), $network->b2bClientIsVisible((string) $request->validated('b2b_client_id'))];

        if (! $visible) {
            throw ValidationException::withMessages([$partnerType === PartnerType::Franchise ? 'franchise_id' : 'b2b_client_id' => 'The selected partner does not exist.']);
        }

        $entry = $adjustments->post(
            $staff->user()->organization_id,
            $partnerType,
            $partnerId,
            $request->validated('side'),
            Money::fromString((string) $request->validated('amount')),
            $request->validated('reason'),
            $request->validated('note'),
        );

        return ApiResponse::created(LedgerEntryResource::make($entry), "/api/v1/ledger/{$entry->id}");
    }
}
