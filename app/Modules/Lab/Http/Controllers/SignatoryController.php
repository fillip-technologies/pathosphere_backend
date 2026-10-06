<?php

namespace App\Modules\Lab\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Lab\Http\Requests\StoreSignatoryRequest;
use App\Modules\Lab\Http\Requests\UpdateSignatoryRequest;
use App\Modules\Lab\Http\Resources\SignatoryResource;
use App\Modules\Lab\Models\Signatory;
use App\Modules\Lab\Services\SignatoryService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Who may sign which department at which lab (spec §7.2, §8 Signatories). */
final class SignatoryController
{
    public function __construct(private readonly SignatoryService $signatories) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'branch_id' => 'branch_id',
                'department_id' => 'department_id',
                'user_id' => 'user_id',
                'is_active' => fn ($query, string $value) => $query->where('is_active', filter_var($value, FILTER_VALIDATE_BOOLEAN)),
            ])
            ->allowSorts(['created_at', 'valid_till'])
            ->apply(Signatory::query());

        return CursorPage::respond($query, $request, SignatoryResource::class);
    }

    public function store(StoreSignatoryRequest $request, StaffContext $staff): Response
    {
        $signatory = $this->signatories->create($staff, $request->safe()->except('signature_image'), $request->file('signature_image'));

        return ApiResponse::created(SignatoryResource::make($signatory), "/api/v1/signatories/{$signatory->id}");
    }

    public function show(Signatory $signatory): Response
    {
        return EntityTag::attach(SignatoryResource::make($signatory)->response(), $signatory);
    }

    public function update(UpdateSignatoryRequest $request, Signatory $signatory): Response
    {
        EntityTag::assertIfMatch($request, $signatory);
        $signatory = $this->signatories->update($signatory, $request->validated());

        return EntityTag::attach(SignatoryResource::make($signatory)->response(), $signatory);
    }

    public function destroy(Signatory $signatory): Response
    {
        $this->signatories->delete($signatory);

        return ApiResponse::noContent();
    }
}
