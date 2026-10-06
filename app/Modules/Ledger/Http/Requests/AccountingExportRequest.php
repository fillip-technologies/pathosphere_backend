<?php

namespace App\Modules\Ledger\Http\Requests;

use App\Modules\Ledger\Enums\AccountingExportFormat;
use App\Modules\Ledger\Enums\AccountingExportKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AccountingExportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(AccountingExportKind::class)],
            'format' => ['required', Rule::enum(AccountingExportFormat::class)],
            // A calendar month that has ended, e.g. 2026-09.
            'month' => ['required', 'date_format:Y-m'],
        ];
    }
}
