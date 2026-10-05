<?php

namespace App\Modules\Shared\Http\Validation;

/** Shared validation patterns for Indian identifiers and contact details. */
final class Formats
{
    public const PHONE = 'regex:/^\+?\d{10,15}$/';

    public const PINCODE = 'regex:/^[1-9]\d{5}$/';

    public const GSTIN = 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    public const PAN = 'regex:/^[A-Z]{5}\d{4}[A-Z]$/';

    public const CODE = 'regex:/^[A-Z0-9][A-Z0-9_-]*$/';
}
