<?php

use App\Modules\Network\Enums\BranchOwnerType;
use App\Modules\Network\Enums\BranchStatus;
use App\Modules\Network\Enums\BranchType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every physical site, company-owned or franchised (spec §7.1).
 * `mrp_price_list_id` is added in Phase 2 with price lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('region_id')->constrained()->restrictOnDelete();
            $table->enumString('owner_type', BranchOwnerType::class);
            $table->foreignUuid('franchise_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('branch_code', 20)->unique();
            $table->string('name', 150);
            $table->enumString('branch_type', BranchType::class);
            $table->string('nabl_certificate_no', 50)->nullable();
            $table->date('nabl_valid_till')->nullable();
            $table->string('hfr_id', 50)->nullable();
            $table->string('clinical_establishment_reg_no', 50)->nullable();
            $table->text('address');
            $table->string('pincode', 6)->index();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('phone', 15);
            $table->json('working_hours')->nullable();
            $table->enumString('status', BranchStatus::class);
            $table->date('opened_at')->nullable();
            $table->actorColumns(withForeignKeys: false);
            $table->standardTimestamps();
            $table->standardSoftDeletes();

            // A franchise branch names its franchise; a company branch never does.
            $table->check(
                "(`owner_type` = 'franchise' and `franchise_id` is not null) or (`owner_type` = 'company' and `franchise_id` is null)",
                'branches_owner_franchise_check',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
