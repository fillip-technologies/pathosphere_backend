<?php

namespace App\Modules\Booking\Http\Requests;

use App\Modules\Booking\Enums\OrderSource;
use App\Modules\Booking\Services\BookOrderCommand;
use App\Modules\Booking\Services\HomeVisitRequest;
use App\Modules\Shared\Http\Validation\Formats;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** POST /orders (spec §8 example). */
final class BookOrderRequest extends FormRequest
{
    use ReadsBookingParts;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'uuid'],
            'branch_id' => ['required', 'uuid'],
            'doctor_id' => ['nullable', 'uuid'],
            'b2b_client_id' => ['nullable', 'uuid'],
            'order_source' => ['required', Rule::enum(OrderSource::class)],
            'external_ref' => ['nullable', 'string', 'max:50'],
            'clinical_notes' => ['nullable', 'string', 'max:2000'],
            ...$this->bookingPartRules(),
            'home_collection' => ['nullable', 'array', 'required_if:order_source,home_collection'],
            'home_collection.address' => ['required_with:home_collection', 'string', 'max:1000'],
            'home_collection.pincode' => ['required_with:home_collection', Formats::PINCODE],
            'home_collection.slot_start' => ['required_with:home_collection', 'date', 'after:now'],
            'home_collection.slot_end' => ['required_with:home_collection', 'date', 'after:home_collection.slot_start'],
            'home_collection.collection_charge' => ['nullable', 'decimal:0,2', 'min:0'],
        ];
    }

    /**
     * Rules that span fields: who pays, and how, depends on the order source.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $source = $this->input('order_source');
            $hasClient = $this->filled('b2b_client_id');
            $hasPayment = $this->filled('payment');

            if (($source === OrderSource::B2b->value) !== $hasClient) {
                $validator->errors()->add('b2b_client_id', 'B2B orders need a client, and only B2B orders may have one.');
            }

            if ($source !== OrderSource::HomeCollection->value && $this->filled('home_collection')) {
                $validator->errors()->add('home_collection', 'Only home-collection orders have visit details.');
            }

            if ($hasPayment && in_array($source, [OrderSource::B2b->value, OrderSource::Online->value], true)) {
                $validator->errors()->add('payment', 'B2B orders are billed on credit and online orders are paid by link.');
            }
        }];
    }

    public function toCommand(): BookOrderCommand
    {
        return new BookOrderCommand(
            $this->validated('patient_id'),
            $this->validated('branch_id'),
            $this->validated('doctor_id'),
            $this->validated('b2b_client_id'),
            OrderSource::from($this->validated('order_source')),
            $this->validated('external_ref'),
            $this->validated('clinical_notes'),
            $this->quoteItems(),
            $this->discountAmount(),
            $this->discountReason(),
            $this->deskPayment(),
            $this->homeVisit(),
        );
    }

    private function homeVisit(): ?HomeVisitRequest
    {
        $visit = $this->validated('home_collection');

        if (! is_array($visit)) {
            return null;
        }

        return new HomeVisitRequest(
            $visit['address'],
            $visit['pincode'],
            CarbonImmutable::parse($visit['slot_start'])->utc(),
            CarbonImmutable::parse($visit['slot_end'])->utc(),
            Money::fromString((string) ($visit['collection_charge'] ?? '0')),
        );
    }
}
