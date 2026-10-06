<?php

namespace App\Modules\Lab\Services;

use App\Modules\Network\Services\BranchLetterhead;
use Carbon\CarbonImmutable;

/**
 * A released report version as it is shared with other health systems
 * (ABDM M2, spec §5.7): who made it, who signed it and how each test is
 * coded. The values themselves are read from the health locker's copy,
 * which keeps each version's results as released.
 */
final class ReportForExchange
{
    /**
     * @param  list<ExchangeTest>  $tests
     * @param  list<ExchangeSigner>  $signers
     */
    public function __construct(
        public readonly string $reportId,
        public readonly string $organizationId,
        public readonly string $patientId,
        public readonly int $version,
        /** False once a corrected version has replaced it. */
        public readonly bool $isCurrent,
        public readonly string $orderNo,
        /** When the sample was taken. */
        public readonly CarbonImmutable $orderDate,
        public readonly CarbonImmutable $releasedAt,
        public readonly BranchLetterhead $lab,
        public readonly array $tests,
        public readonly array $signers,
        public readonly bool $pdfReady,
    ) {}

    public function signerFor(string $departmentId): ?ExchangeSigner
    {
        foreach ($this->signers as $signer) {
            if ($signer->departmentId === $departmentId) {
                return $signer;
            }
        }

        return null;
    }
}
