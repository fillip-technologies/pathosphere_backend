<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Modules\Shared\Audit\AuditLog;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Resources\AuditLogResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** GET /audit-logs: newest first, filtered to one entity or one person. */
final class AuditLogController
{
    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'entity_type' => 'entity_type',
                'entity_id' => 'entity_id',
                'user_id' => 'user_id',
                'action' => 'action',
                'branch_id' => 'branch_id',
                'franchise_id' => 'franchise_id',
            ])
            ->apply(AuditLog::query()->orderByDesc('id')); // UUIDv7: newest first

        return CursorPage::respond($query, $request, AuditLogResource::class);
    }
}
