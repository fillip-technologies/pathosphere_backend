<?php

namespace Tests\Support\Architecture;

/**
 * Money is never a float (spec §6.6). Applies to Money, Domain/ logic and the
 * Ledger module.
 */
final class NoFloatInMoneyCode implements ArchitectureRule
{
    /** Reviewed exceptions: Domain code that holds no money. */
    private const ALLOWED_FILES = [
        // FHIR Observation values must be JSON numbers (lab results, never money).
        'app/Modules/Locker/Domain/DiagnosticReportRecord.php',
        // Haversine trigonometry for home-collection routes; returns whole metres.
        'app/Modules/Booking/Domain/RoadDistance.php',
    ];

    public function description(): string
    {
        return 'Money code (Shared/Money, */Domain/, Ledger) must not use float.';
    }

    public function violations(array $files): array
    {
        $violations = [];

        foreach ($files as $file) {
            $isMoneyCode = $file->isUnder('/Shared/Money/') || $file->isUnder('/Domain/') || $file->isUnder('/Modules/Ledger/');

            if (! $isMoneyCode || array_filter(self::ALLOWED_FILES, fn (string $path): bool => str_ends_with($file->path, $path)) !== []) {
                continue;
            }

            if (preg_match('/\bfloat\b|\bfloatval\s*\(|\(\s*double\s*\)/i', $file->code) === 1) {
                $violations[] = "{$file->path} uses float.";
            }
        }

        return $violations;
    }
}
