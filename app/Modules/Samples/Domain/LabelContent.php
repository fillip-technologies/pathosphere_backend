<?php

namespace App\Modules\Samples\Domain;

/** What is printed on a tube label. */
final class LabelContent
{
    /** @param  list<string>  $testNames  short names, as space allows */
    public function __construct(
        public readonly string $barcode,
        public readonly string $patientName,
        /** e.g. "36Y F" */
        public readonly string $ageAndGender,
        public readonly string $orderNo,
        public readonly string $containerType,
        public readonly array $testNames,
    ) {}
}
