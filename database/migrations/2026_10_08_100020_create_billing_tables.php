<?php

use App\Modules\Booking\Enums\BillToType;
use App\Modules\Booking\Enums\InvoicePaymentStatus;
use App\Modules\Booking\Enums\PaymentMode;
use App\Modules\Booking\Enums\PaymentStatus;
use App\Modules\Booking\Enums\RefundStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Invoices, payments, refunds and raw gateway webhooks (spec §7.5). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('invoice_no', 40)->unique();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('billed_by_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->restrictOnDelete();
            $table->enumString('bill_to_type', BillToType::class);
            $table->foreignUuid('b2b_client_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('invoice_date')->index();
            $table->date('due_date')->nullable();
            $table->money('amount');
            $table->money('discount')->default(0);
            $table->money('tax')->default(0);
            $table->money('total');
            $table->money('amount_paid')->default(0);
            $table->enumString('payment_status', InvoicePaymentStatus::class);
            $table->string('irn', 64)->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->index(['franchise_id', 'invoice_date']);
            $table->check('`total` = `amount` - `discount` + `tax`', 'invoices_total_check');
            $table->check('`amount_paid` >= 0 and `amount_paid` <= `total`', 'invoices_amount_paid_check');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $table->money('amount');
            $table->enumString('mode', PaymentMode::class);
            $table->string('gateway', 32)->nullable();
            $table->string('transaction_id', 100)->nullable();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('paid_at', 6);
            $table->enumString('status', PaymentStatus::class);
            $table->actorColumns();
            $table->standardTimestamps();

            $table->unique(['gateway', 'transaction_id']);
            $table->check('`amount` > 0', 'payments_amount_check');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('payment_id')->constrained()->restrictOnDelete();
            $table->money('amount');
            $table->string('reason', 100);
            $table->string('gateway_refund_id', 100)->nullable();
            $table->foreignUuid('approved_by')->constrained('users')->restrictOnDelete();
            $table->enumString('status', RefundStatus::class);
            $table->actorColumns();
            $table->standardTimestamps();

            $table->check('`amount` > 0', 'refunds_amount_check');
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->string('gateway', 32);
            $table->string('event_id', 100);
            $table->string('event_type', 60);
            $table->json('payload');
            $table->boolean('signature_valid');
            $table->dateTime('processed_at', 6)->nullable();
            $table->text('error')->nullable();
            $table->standardTimestamps();

            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
    }
};
