<?php

namespace App\Modules\Samples\Http\Requests;

use App\Modules\Samples\Enums\ManifestItemCondition;
use App\Modules\Samples\Services\ReceiptScan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Barcodes scanned at the receiving lab, each accepted or rejected with a reason. */
final class ReceiveManifestRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.barcode' => ['required', 'string', 'max:30', 'distinct'],
            'items.*.condition' => ['required', Rule::in([ManifestItemCondition::Accepted->value, ManifestItemCondition::Rejected->value])],
            'items.*.rejection_reason' => [
                'nullable',
                'required_if:items.*.condition,rejected',
                'prohibited_if:items.*.condition,accepted',
                Rule::in(array_keys(config('pathology.samples.rejection_reasons'))),
            ],
            'items.*.rejection_note' => ['nullable', 'string', 'max:1000'],
            'temperature_ok' => ['nullable', 'boolean'],
            'receipt_temp_c' => ['nullable', 'decimal:0,1', 'between:-99.9,99.9'],
        ];
    }

    /** @return list<ReceiptScan> */
    public function scans(): array
    {
        return array_map(fn (array $item) => new ReceiptScan(
            $item['barcode'],
            ManifestItemCondition::from($item['condition']),
            $item['rejection_reason'] ?? null,
            $item['rejection_note'] ?? null,
        ), array_values($this->validated('items')));
    }
}
