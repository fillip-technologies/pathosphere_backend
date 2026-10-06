<?php

use App\Modules\Network\Enums\AgreementStatus;
use App\Modules\Network\Enums\BillingModel;
use App\Modules\Network\Enums\FranchiseDocumentStatus;
use App\Modules\Network\Enums\FranchiseDocumentType;
use App\Modules\Network\Enums\FranchiseModel;
use App\Modules\Network\Enums\SettlementCycle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Franchise agreements, their territory and KYC documents (spec §7.1).
 * Additions to the spec: `organization_id` on agreements and documents for
 * organization scoping, and `signed_at` on agreements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchise_agreements', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('franchise_id')->constrained()->restrictOnDelete();
            $table->string('agreement_no', 30)->unique();
            $table->enumString('franchise_model', FranchiseModel::class);
            $table->enumString('billing_model', BillingModel::class);
            $table->percentage('commission_pct')->nullable();
            $table->money('franchise_fee')->default(0);
            $table->money('security_deposit')->default(0);
            $table->money('min_monthly_business')->nullable();
            $table->text('territory')->nullable();
            $table->enumString('settlement_cycle', SettlementCycle::class);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('signed_doc_path', 500)->nullable();
            $table->string('esign_reference', 100)->nullable()->unique();
            $table->dateTime('signed_at', 6)->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->enumString('status', AgreementStatus::class);
            $table->actorColumns();
            $table->standardTimestamps();
            $table->standardSoftDeletes();

            $table->uniqueWhere(['franchise_id'], "`status` = 'active' and `deleted_at` is null", 'is_active_agreement');
            $table->check('`end_date` > `start_date`', 'franchise_agreements_dates_check');
            $table->check('`commission_pct` is null or (`commission_pct` >= 0 and `commission_pct` <= 100)', 'franchise_agreements_commission_range_check');
            $table->check("`billing_model` <> 'revenue_share' or `commission_pct` is not null", 'franchise_agreements_commission_required_check');
            $table->check('`franchise_fee` >= 0 and `security_deposit` >= 0', 'franchise_agreements_amounts_check');
        });

        // Spec §6: the territory_pincodes array becomes a child table.
        Schema::create('franchise_territory_pincodes', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('agreement_id')->constrained('franchise_agreements')->cascadeOnDelete();
            $table->string('pincode', 6)->index();
            $table->standardTimestamps();

            $table->unique(['agreement_id', 'pincode']);
        });

        Schema::create('franchise_documents', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('franchise_id')->constrained()->restrictOnDelete();
            $table->enumString('doc_type', FranchiseDocumentType::class);
            $table->string('file_path', 500);
            $table->string('vendor_reference', 100)->nullable();
            $table->enumString('status', FranchiseDocumentStatus::class);
            $table->text('rejection_note')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('verified_at', 6)->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->index(['franchise_id', 'doc_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchise_documents');
        Schema::dropIfExists('franchise_territory_pincodes');
        Schema::dropIfExists('franchise_agreements');
    }
};
