<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Contracts\JournalBook;
use App\Modules\Ledger\Contracts\JournalFileWriter;
use App\Modules\Ledger\Domain\JournalVoucher;
use App\Modules\Ledger\Enums\AccountingExportFormat;
use App\Modules\Ledger\Enums\AccountingExportKind;
use App\Modules\Ledger\Enums\AccountingExportStatus;
use App\Modules\Ledger\Errors\LedgerError;
use App\Modules\Ledger\Infrastructure\TallyXmlJournal;
use App\Modules\Ledger\Infrastructure\ZohoBooksJournalCsv;
use App\Modules\Ledger\Jobs\GenerateAccountingExport;
use App\Modules\Ledger\Models\AccountingExport;
use App\Modules\Ledger\StateMachines\AccountingExportStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Files\PrivateFileStore;
use App\Modules\Shared\Files\PrivatePaths;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Monthly files for the company's books (spec §3: Tally XML, Zoho Books;
 * export only, the platform is not the books of account). Asked for by HQ
 * Finance, built in the background, kept in private storage.
 */
final class AccountingExports
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public function __construct(
        private readonly AccountingVouchers $vouchers,
        private readonly AccountingExportStateMachine $stateMachine,
        private readonly PrivateFileStore $files,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** Queues the export of one calendar month that has ended. */
    public function request(string $organizationId, AccountingExportKind $kind, AccountingExportFormat $format, CarbonImmutable $month): AccountingExport
    {
        $periodStart = $month->startOfMonth();
        $periodEnd = $month->endOfMonth()->startOfDay();

        if (! $periodEnd->lessThan(CarbonImmutable::now(self::BUSINESS_TIMEZONE)->startOfDay())) {
            throw LedgerError::accountingPeriodOpen($periodStart->format('Y-m'));
        }

        return DB::transaction(function () use ($organizationId, $kind, $format, $periodStart, $periodEnd): AccountingExport {
            $export = new AccountingExport([
                'kind' => $kind,
                'format' => $format,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'status' => AccountingExportStatus::Queued,
            ]);
            $export->organization_id = $organizationId;
            $export->save();
            $this->auditLogger->recordCreated('accounting_export.requested', $export);

            GenerateAccountingExport::dispatch($export->id, $organizationId)->afterCommit();

            return $export;
        });
    }

    /** Builds and stores the file. Idempotent: a finished export is left alone. */
    public function generate(string $exportId): void
    {
        $export = AccountingExport::query()->find($exportId);

        if ($export === null || $export->status !== AccountingExportStatus::Queued) {
            return;
        }

        $vouchers = match ($export->kind) {
            AccountingExportKind::Sales => $this->vouchers->sales($export->organization_id, $export->period_start, $export->period_end),
            AccountingExportKind::PartnerLedger => $this->vouchers->partnerLedger($export->organization_id, $export->period_start, $export->period_end),
        };
        $writer = $this->writer($export->format);
        $book = new JournalBook((string) config('pathology.accounting.tally_company'), (string) config('pathology.accounting.currency'), $export->period_start, $export->period_end, $vouchers);

        $path = PrivatePaths::accountingExport($export->id, $export->period_start, $writer->extension());
        // A retry after the file was stored keeps that file rather than writing a second one.
        $stored = $this->files->exists($path) ? null : $this->files->putNew($path, $writer->write($book));

        $this->stateMachine->transition($export, AccountingExportStatus::Ready, [
            'file_path' => $path,
            'checksum' => $stored->sha256 ?? $this->files->sha256($path),
            'size_bytes' => $stored->sizeBytes ?? strlen($this->files->contents($path)),
            'voucher_count' => count($vouchers),
            'total_amount' => array_reduce($vouchers, fn (Money $sum, JournalVoucher $voucher) => $sum->add($voucher->totalDebit()), Money::zero()),
            'generated_at' => CarbonImmutable::now(),
        ]);
    }

    public function markFailed(string $exportId, string $reason): void
    {
        $export = AccountingExport::query()->find($exportId);

        if ($export === null || $export->status !== AccountingExportStatus::Queued) {
            return;
        }

        $this->stateMachine->transition($export, AccountingExportStatus::Failed, ['error_message' => mb_substr($reason, 0, 500)]);
    }

    /** The file, or null while it is still being built. */
    public function download(AccountingExport $export): ?StreamedResponse
    {
        if ($export->status === AccountingExportStatus::Failed) {
            throw LedgerError::accountingExportFailed();
        }

        if ($export->status === AccountingExportStatus::Queued || $export->file_path === null) {
            return null;
        }

        $this->auditLogger->record('accounting_export.downloaded', $export);
        $writer = $this->writer($export->format);
        $name = sprintf('%s-%s-%s.%s', str_replace('_', '-', $export->kind->value), $export->period_start->format('Y-m'), str_replace('_', '-', $export->format->value), $writer->extension());

        $response = $this->files->download($export->file_path, $name);
        $response->headers->set('Content-Type', $writer->mimeType());

        return $response;
    }

    private function writer(AccountingExportFormat $format): JournalFileWriter
    {
        return match ($format) {
            AccountingExportFormat::TallyXml => new TallyXmlJournal,
            AccountingExportFormat::ZohoBooksCsv => new ZohoBooksJournalCsv((string) config('pathology.accounting.zoho_receivables_account')),
        };
    }
}
