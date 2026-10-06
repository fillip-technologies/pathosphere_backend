<?php

namespace App\Modules\Locker\Http\Controllers;

use App\Modules\Locker\Enums\AccessAction;
use App\Modules\Locker\Enums\AccessActorType;
use App\Modules\Locker\Enums\RecordSource;
use App\Modules\Locker\Http\Requests\RecordFileRequest;
use App\Modules\Locker\Http\Requests\UploadRecordRequest;
use App\Modules\Locker\Http\Resources\MedicalRecordDetailResource;
use App\Modules\Locker\Http\Resources\MedicalRecordResource;
use App\Modules\Locker\Models\MedicalRecord;
use App\Modules\Locker\Services\LockerRecords;
use App\Modules\Locker\Services\PatientViewer;
use App\Modules\Locker\Services\RecordAccessLogger;
use App\Modules\Locker\Services\RecordFiles;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The patient's health locker (spec §8 Patient app): the timeline, lab
 * reports, one record, its file, and uploads. Opening a record or its file
 * is logged in record_access_logs; lists show titles and dates only.
 */
final class RecordController
{
    public function __construct(
        private readonly LockerRecords $records,
        private readonly PatientViewer $viewer,
        private readonly RecordAccessLogger $accessLogger,
    ) {}

    /** GET /me/records: every current record, newest first. */
    public function index(Request $request): Response
    {
        return $this->list($request, $this->records->timeline($this->viewer));
    }

    /** GET /me/reports: the lab reports from our network, newest first. */
    public function reports(Request $request): Response
    {
        return $this->list($request, $this->records->timeline($this->viewer)->where('source', RecordSource::OwnLab));
    }

    public function show(string $recordId): Response
    {
        $record = $this->records->find($this->viewer, $recordId);
        $this->accessLogger->log([$record->id], AccessActorType::Patient, $this->viewer->holder()->id, AccessAction::View);

        return MedicalRecordDetailResource::make($record)->response();
    }

    /** GET /me/records/{id}/file?version=: the lab's PDF, or the uploaded file (newest version by default). */
    public function file(Request $request, string $recordId, RecordFiles $files): Response
    {
        $version = $this->version($request);
        $record = $this->records->find($this->viewer, $recordId);
        $response = $files->download($record, $this->viewer->organizationId(), $version);
        $this->accessLogger->log([$record->id], AccessActorType::Patient, $this->viewer->holder()->id, AccessAction::Download);

        return $response;
    }

    public function store(UploadRecordRequest $request): Response
    {
        /** @var array{category: string, title: string, record_date: string, provider_facility?: string|null} $details */
        $details = $request->safe()->except('file');
        $record = $this->records->upload($this->viewer, $details, $request->file('file'));

        return ApiResponse::created(MedicalRecordDetailResource::make($record), "/api/v1/me/records/{$record->id}");
    }

    /** POST /me/records/{id}/documents: a new version of an uploaded file. */
    public function addVersion(RecordFileRequest $request, string $recordId): Response
    {
        $record = $this->records->addVersion($this->viewer, $recordId, $request->file('file'));

        return ApiResponse::created(MedicalRecordDetailResource::make($record), "/api/v1/me/records/{$record->id}");
    }

    /** @param  Builder<MedicalRecord>  $query */
    private function list(Request $request, Builder $query): Response
    {
        ListQuery::from($request)
            ->allowFilters([
                'category' => fn ($query, string $code) => $query->whereHas('category', fn ($categories) => $categories->where('code', $code)),
                'source' => function ($query, string $source): void {
                    if (RecordSource::tryFrom($source) === null) {
                        throw ValidationException::withMessages(['filter.source' => 'Choose one of: '.implode(', ', array_column(RecordSource::cases(), 'value')).'.']);
                    }

                    $query->where('source', $source);
                },
                'from' => fn ($query, string $date) => $query->where('record_date', '>=', $this->date('from', $date)),
                'to' => fn ($query, string $date) => $query->where('record_date', '<=', $this->date('to', $date)),
            ])
            ->allowSorts(['record_date', 'created_at'])
            ->apply($query);

        if (! $request->filled('sort')) {
            // Newest first, and the latest added first on the same date.
            $query->orderByDesc('record_date')->orderByDesc('id');
        }

        return CursorPage::respond($query, $request, MedicalRecordResource::class);
    }

    private function date(string $filter, string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || strtotime($value) === false) {
            throw ValidationException::withMessages(["filter.{$filter}" => 'Use a date like 2026-10-31.']);
        }

        return $value;
    }

    private function version(Request $request): ?int
    {
        $raw = $request->query('version');

        if ($raw === null) {
            return null;
        }

        $version = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($version === false) {
            throw ValidationException::withMessages(['version' => 'The version must be a whole number from 1.']);
        }

        return $version;
    }
}
