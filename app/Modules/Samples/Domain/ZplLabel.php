<?php

namespace App\Modules\Samples\Domain;

/**
 * Builds the ZPL for a 50 × 25 mm tube label (203 dpi thermal printers):
 * patient line, Code 128 barcode with its text, order number, container and
 * tests. The desk app sends it to the printer as is.
 */
final class ZplLabel
{
    private const LINE_CHARACTERS = 32;

    public static function render(LabelContent $label): string
    {
        $patientLine = self::fit("{$label->patientName} {$label->ageAndGender}");
        $detailLine = self::fit("{$label->orderNo} {$label->containerType}");
        $testsLine = self::fit(implode(', ', $label->testNames));

        return implode("\n", [
            '^XA',
            '^CI28',
            '^PW400',
            '^LL200',
            '^FO15,10^A0N,22,22^FD'.$patientLine.'^FS',
            '^FO15,38^BY2^BCN,70,Y,N,N^FD'.self::field($label->barcode).'^FS',
            '^FO15,140^A0N,20,20^FD'.$detailLine.'^FS',
            '^FO15,165^A0N,20,20^FD'.$testsLine.'^FS',
            '^XZ',
        ]);
    }

    private static function fit(string $text): string
    {
        return self::field(mb_strimwidth($text, 0, self::LINE_CHARACTERS, '…'));
    }

    /** ^ and ~ start ZPL commands; patient-entered text must never inject one. */
    private static function field(string $text): string
    {
        return str_replace(['^', '~'], ' ', $text);
    }
}
