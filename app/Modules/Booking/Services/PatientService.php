<?php

namespace App\Modules\Booking\Services;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Enums\AbhaStatus;
use App\Modules\Booking\Errors\BookingError;
use App\Modules\Booking\Models\Patient;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Numbering\NumberSequenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Patient registration, correction, search and duplicate merge (spec §5.2, §7.3). */
final class PatientService
{
    private const DEFAULT_UHID_FORMAT = 'UH{seq:8}';

    private const MAX_MERGE_CHAIN = 10;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NetworkDirectory $network,
        private readonly NumberSequenceService $sequences,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated fields, plus `whatsapp_opt_in`
     */
    public function register(StaffContext $staff, array $attributes): Patient
    {
        $organizationId = $staff->user()->organization_id;
        $branchId = $attributes['registered_branch_id'] ?? $staff->user()->branch_id;

        if ($branchId === null || ! $this->network->branchIsVisible($branchId)) {
            throw ValidationException::withMessages(['registered_branch_id' => 'Choose the branch where the patient is registered.']);
        }

        return DB::transaction(function () use ($organizationId, $branchId, $attributes): Patient {
            $patient = new Patient($this->patientFields($attributes));
            $patient->organization_id = $organizationId;
            $patient->registered_branch_id = $branchId;
            $patient->uhid = $this->sequences->nextFormatted(
                $organizationId,
                'uhid',
                (string) ($this->network->organizationSettings($organizationId)['uhid_format'] ?? self::DEFAULT_UHID_FORMAT),
            );
            $patient->consented_at = now();
            $patient->whatsapp_opted_in_at = ($attributes['whatsapp_opt_in'] ?? false) ? now() : null;
            $patient->save();
            $this->auditLogger->recordCreated('patient.create', $patient);

            return $patient;
        });
    }

    /** @param  array<string, mixed>  $changes */
    public function update(Patient $patient, array $changes): Patient
    {
        $fields = $this->patientFields($changes);

        if (array_key_exists('whatsapp_opt_in', $changes)) {
            $fields['whatsapp_opted_in_at'] = $changes['whatsapp_opt_in']
                ? ($patient->whatsapp_opted_in_at ?? now())
                : null;
        }

        return DB::transaction(function () use ($patient, $fields): Patient {
            $patient->fill($fields)->save();
            $this->auditLogger->recordChanges('patient.update', $patient);

            return $patient;
        });
    }

    /**
     * Front-desk search (spec §5.2 step 1). The text decides the lookup:
     * phone, UHID, ABHA number, ABHA address, else name. Merged records are
     * hidden; their surviving record is found instead.
     *
     * @param  Builder<Patient>  $query
     */
    public static function applySearch(Builder $query, string $text): void
    {
        $text = trim($text);
        $digits = preg_replace('/\D/', '', $text) ?? '';
        $query->whereNull('merged_into_id');

        match (true) {
            preg_match('/^\+?\d{10,12}$/', $text) === 1 => $query->where('phone', substr($digits, -10)),
            preg_match('/^[A-Za-z]{2}\d{4,}$/', $text) === 1 => $query->where('uhid', strtoupper($text)),
            strlen($digits) === 14 && preg_match('/^[\d -]+$/', $text) === 1 => $query->where('abha_number_hash', Patient::abhaNumberHash($digits)),
            str_contains($text, '@') => $query->where('abha_address', mb_strtolower($text)),
            mb_strlen($text) >= 3 => $query->whereFullText('name', self::namePrefixQuery($text), ['mode' => 'boolean']),
            default => throw ValidationException::withMessages(['q' => 'Search with a phone, UHID, ABHA or at least 3 letters of the name.']),
        };
    }

    /**
     * "ram kum" → "+ram* +kum*": every word must start a word in the name.
     * Boolean-mode operators typed by users are stripped.
     */
    private static function namePrefixQuery(string $text): string
    {
        $words = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(fn (string $word) => "+{$word}*", $words));
    }

    /** Follows merge pointers to the record that survives (spec §7.3: reads follow the pointer). */
    public function surviving(Patient $patient): Patient
    {
        for ($hops = 0; $patient->merged_into_id !== null && $hops < self::MAX_MERGE_CHAIN; $hops++) {
            $patient = Patient::query()->findOrFail($patient->merged_into_id);
        }

        return $patient;
    }

    /**
     * Marks a duplicate as merged into the record that stays. Nothing is
     * deleted: orders and reports keep pointing at the record they used.
     */
    public function merge(Patient $duplicate, Patient $survivor): Patient
    {
        $reason = match (true) {
            $duplicate->is($survivor) => 'A patient cannot be merged into itself.',
            $duplicate->merged_into_id !== null || $survivor->merged_into_id !== null => 'One of these records is already merged.',
            $duplicate->abha_status === AbhaStatus::Linked && $survivor->abha_status === AbhaStatus::Linked => 'Both records have an ABHA linked; unlink one first.',
            default => null,
        };

        if ($reason !== null) {
            throw BookingError::cannotMerge($reason);
        }

        return DB::transaction(function () use ($duplicate, $survivor): Patient {
            $duplicate->merged_into_id = $survivor->id;
            $duplicate->save();
            $this->auditLogger->recordChanges('patient.merge', $duplicate);

            return $survivor;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function patientFields(array $attributes): array
    {
        $fields = array_intersect_key($attributes, array_flip([
            'salutation', 'name', 'dob', 'age_years', 'gender', 'phone', 'email', 'address', 'pincode',
            'guardian_patient_id', 'consent_notice_version',
        ]));

        if (isset($fields['phone'])) {
            $fields['phone'] = substr(preg_replace('/\D/', '', $fields['phone']) ?? '', -10);
        }

        return $fields;
    }
}
