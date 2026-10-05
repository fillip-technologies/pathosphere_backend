<?php

namespace App\Modules\Catalogue\Http\Requests;

use App\Modules\Catalogue\Domain\QuoteRequestItem;
use Illuminate\Foundation\Http\FormRequest;

final class OrderQuoteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid'],
            'b2b_client_id' => ['nullable', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.test_id' => ['nullable', 'uuid', 'required_without:items.*.package_id', 'prohibits:items.*.package_id'],
            'items.*.package_id' => ['nullable', 'uuid', 'required_without:items.*.test_id'],
        ];
    }

    /** @return list<QuoteRequestItem> */
    public function quoteItems(): array
    {
        return array_values(array_map(
            fn (array $item): QuoteRequestItem => isset($item['test_id'])
                ? QuoteRequestItem::test($item['test_id'])
                : QuoteRequestItem::package($item['package_id']),
            $this->validated('items'),
        ));
    }
}
