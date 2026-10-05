<?php

namespace Tests\Support\Architecture;

/**
 * Money is never a float (spec §6.6). Applies to Money, Domain/ logic and the
 * Ledger module.
 */
final class NoFloatInMoneyCode implements ArchitectureRule
{
    public function description(): string
    {
        return 'Money code (Shared/Money, */Domain/, Ledger) must not use float.';
    }

    public function violations(array $files): array
    {
        $violations = [];

        foreach ($files as $file) {
            $isMoneyCode = $file->isUnder('/Shared/Money/') || $file->isUnder('/Domain/') || $file->isUnder('/Modules/Ledger/');

            if (! $isMoneyCode) {
                continue;
            }

            if (preg_match('/\bfloat\b|\bfloatval\s*\(|\(\s*double\s*\)/i', $file->code) === 1) {
                $violations[] = "{$file->path} uses float.";
            }
        }

        return $violations;
    }
}
