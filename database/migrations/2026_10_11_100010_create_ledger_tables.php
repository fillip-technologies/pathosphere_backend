<?php

use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SettlementDirection;
use App\Modules\Ledger\Enums\SettlementStatus;
use App\Modules\Ledger\Enums\WalletTopupStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner ledger and settlements (spec §7.8), plus wallet top-ups.
 *
 * Additions to the spec: `partner_ledger.idempotency_key` (unique; a retried
 * job or webhook can never post the same money twice, spec §9), settlement
 * `organization_id`, `closing_balance` and `dispute_note`, and the
 * `wallet_topups` table that ties a gateway payment link to its ledger credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Append-only: the app's database user may only SELECT and INSERT here (spec §4.5).
        Schema::create('partner_ledger', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('b2b_client_id')->nullable()->constrained()->restrictOnDelete();
            $table->enumString('entry_type', LedgerEntryType::class);
            $table->string('reference_type', 32);
            $table->uuid('reference_id')->nullable();
            $table->money('debit')->default(0);
            $table->money('credit')->default(0);
            $table->money('balance_after');
            $table->string('narration', 255);
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->standardTimestamps();

            $table->exactlyOneOf('franchise_id', 'b2b_client_id');
            $table->check('`debit` >= 0 and `credit` >= 0 and (`debit` = 0 or `credit` = 0) and `debit` + `credit` > 0', 'partner_ledger_amounts_check');
            $table->index(['franchise_id', 'created_at']);
            $table->index(['b2b_client_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('settlement_no', 30)->unique();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('b2b_client_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->money('gross_billing');
            $table->money('partner_share');
            $table->money('hq_share');
            $table->money('tax')->default(0);
            $table->money('net_amount');
            $table->money('closing_balance');
            $table->enumString('direction', SettlementDirection::class);
            $table->enumString('status', SettlementStatus::class);
            $table->string('statement_pdf_path', 500)->nullable();
            $table->text('dispute_note')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('settled_at', 6)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->exactlyOneOf('franchise_id', 'b2b_client_id');
            $table->unique(['franchise_id', 'period_start']);
            $table->unique(['b2b_client_id', 'period_start']);
            $table->check('`period_end` >= `period_start`', 'settlements_period_check');
            $table->check('`net_amount` >= 0 and `tax` >= 0', 'settlements_amounts_check');
            $table->index(['status', 'period_end']);
        });

        Schema::create('settlement_items', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('settlement_id')->constrained()->cascadeOnDelete();
            // A ledger row settles once (spec §5.6).
            $table->foreignUuid('ledger_entry_id')->unique()->constrained('partner_ledger')->restrictOnDelete();
            $table->standardTimestamps();
        });

        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('franchise_id')->constrained()->restrictOnDelete();
            $table->money('amount');
            $table->enumString('status', WalletTopupStatus::class);
            $table->string('gateway', 32);
            $table->string('payment_link_id', 100)->nullable();
            $table->dateTime('link_expires_at', 6)->nullable();
            $table->string('gateway_payment_id', 100)->nullable();
            $table->dateTime('paid_at', 6)->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->unique(['gateway', 'gateway_payment_id']);
            $table->index(['franchise_id', 'created_at']);
            $table->check('`amount` > 0', 'wallet_topups_amount_check');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_topups');
        Schema::dropIfExists('settlement_items');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('partner_ledger');
    }
};
