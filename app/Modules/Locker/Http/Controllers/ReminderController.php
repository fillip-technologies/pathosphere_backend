<?php

namespace App\Modules\Locker\Http\Controllers;

use App\Modules\Locker\Enums\ReminderStatus;
use App\Modules\Locker\Http\Requests\ReminderRequest;
use App\Modules\Locker\Http\Resources\ReminderResource;
use App\Modules\Locker\Services\PatientViewer;
use App\Modules\Locker\Services\ReminderService;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Follow-up test and vaccine reminders the patient sets. */
final class ReminderController
{
    public function __construct(
        private readonly ReminderService $reminders,
        private readonly PatientViewer $viewer,
    ) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['status' => function ($query, string $status): void {
                if (ReminderStatus::tryFrom($status) === null) {
                    throw ValidationException::withMessages(['filter.status' => 'Choose one of: '.implode(', ', array_column(ReminderStatus::cases(), 'value')).'.']);
                }

                $query->where('status', $status);
            }])
            ->allowSorts(['remind_at', 'created_at'])
            ->apply($this->reminders->list($this->viewer));

        if (! $request->filled('sort')) {
            $query->orderBy('remind_at');
        }

        return CursorPage::respond($query, $request, ReminderResource::class);
    }

    public function store(ReminderRequest $request): Response
    {
        $reminder = $this->reminders->create($this->viewer, $request->validated());

        return ApiResponse::created(ReminderResource::make($reminder), "/api/v1/me/reminders/{$reminder->id}");
    }

    public function show(string $reminderId): Response
    {
        return ReminderResource::make($this->reminders->find($this->viewer, $reminderId))->response();
    }

    public function dismiss(string $reminderId): Response
    {
        return ReminderResource::make($this->reminders->dismiss($this->viewer, $reminderId))->response();
    }
}
