<?php

namespace App\Modules\Shared\Files;

use DateTimeInterface;

/**
 * The private-storage folder layout from spec §3. File names use IDs only,
 * never patient names or phone numbers.
 */
final class PrivatePaths
{
    public static function report(string $reportId, int $version, DateTimeInterface $releasedAt): string
    {
        return sprintf('reports/%s/%s_v%d.pdf', $releasedAt->format('Y/m'), $reportId, $version);
    }

    public static function invoice(string $invoiceId, DateTimeInterface $invoiceDate): string
    {
        return sprintf('invoices/%s/%s.pdf', $invoiceDate->format('Y/m'), $invoiceId);
    }

    public static function kycDocument(string $franchiseId, string $documentId, string $extension): string
    {
        return sprintf('kyc/%s/%s.%s', $franchiseId, $documentId, self::cleanExtension($extension));
    }

    /** A franchise agreement as sent for signature, or the signed copy returned by the e-sign vendor. */
    public static function agreement(string $franchiseId, string $agreementId, bool $signed): string
    {
        return sprintf('agreements/%s/%s_%s.pdf', $franchiseId, $agreementId, $signed ? 'signed' : 'unsigned');
    }

    public static function settlementStatement(string $settlementId, DateTimeInterface $periodEnd): string
    {
        return sprintf('statements/%s/%s.pdf', $periodEnd->format('Y/m'), $settlementId);
    }

    public static function signature(string $signatoryId): string
    {
        return sprintf('signatures/%s.png', $signatoryId);
    }

    public static function lockerUpload(string $patientId, string $documentId, string $extension): string
    {
        return sprintf('uploads/locker/%s/%s.%s', $patientId, $documentId, self::cleanExtension($extension));
    }

    private static function cleanExtension(string $extension): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $extension) ?? '');
    }
}
