<?php

namespace App\Modules\Booking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /orders/{id}/items: add-on tests with their own invoice. */
final class AddOrderItemsRequest extends FormRequest
{
    use ReadsBookingParts;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->bookingPartRules();
    }
}
