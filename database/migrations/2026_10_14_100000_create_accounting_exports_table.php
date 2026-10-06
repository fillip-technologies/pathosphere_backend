<?php

use App\Modules\Ledger\Enums\AccountingExportFormat;
use App\Modules\Ledger\Enums\AccountingExportKind;
use App\Modules\Ledger\Enums\AccountingExportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting exports (addition; spec §3 "Tally XML export, Zoho Books":
 * monthly sales and settlement journals, export only). One row per file
 * HQ Finance asks for; the file is built in the background and kept in
 * private storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_exports', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->enumString('kind', AccountingExportKind::class);
            $table->enumString('format', AccountingExportFormat::class);
            // First and last business day covered (one calendar month).
            $table->date('period_start');
            $table->date('period_end');
            $table->enumString('status', AccountingExportStatus::class);
            $table->string('file_path', 500)->nullable();
            $table->sha256('checksum')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->unsignedInteger('voucher_count')->nullable();
            // Debits equal credits in every voucher; this is either side's total.
            $table->money('total_amount')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->dateTime('generated_at', 6)->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->index(['organization_id', 'period_start'], 'accounting_exports_org_period_index');
            $table->check('`period_end` >= `period_start`', 'accounting_exports_period_check');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_exports');
    }
};
