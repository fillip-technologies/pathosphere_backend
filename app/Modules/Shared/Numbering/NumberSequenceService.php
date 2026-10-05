<?php

namespace App\Modules\Shared\Numbering;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Hands out gap-free, never-duplicated document numbers (invoices, orders,
 * UHIDs, barcodes, manifests). MySQL has no sequences, so the counter row is
 * locked with SELECT … FOR UPDATE (spec §6.9). Never use max()+1.
 *
 * Must be called inside the same transaction that saves the document; if that
 * transaction rolls back, the number is released with it.
 */
final class NumberSequenceService
{
    /** Series that never restart use this instead of a financial year. */
    public const NO_FINANCIAL_YEAR = 'all';

    public function next(string $organizationId, string $seriesKey, ?FinancialYear $financialYear = null): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Number sequences must be taken inside the transaction that saves the document.');
        }

        $yearLabel = $financialYear?->label() ?? self::NO_FINANCIAL_YEAR;

        // Create the counter on first use; a parallel request creating it too is harmless.
        NumberSequence::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
            'series_key' => $seriesKey,
            'financial_year' => $yearLabel,
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counter = NumberSequence::query()
            ->where('organization_id', $organizationId)
            ->where('series_key', $seriesKey)
            ->where('financial_year', $yearLabel)
            ->lockForUpdate()
            ->firstOrFail();

        $value = $counter->next_value;
        $counter->next_value = $value + 1;
        $counter->save();

        return $value;
    }

    /**
     * @param  array<string, string>  $tokens  values for the template, e.g. ['branch_code' => 'PAT01']
     */
    public function nextFormatted(
        string $organizationId,
        string $seriesKey,
        string $template,
        array $tokens = [],
        ?FinancialYear $financialYear = null,
    ): string {
        $sequence = $this->next($organizationId, $seriesKey, $financialYear);

        if ($financialYear !== null) {
            $tokens['FY'] = $financialYear->shortLabel();
        }

        return NumberFormat::render($template, $sequence, $tokens);
    }
}
