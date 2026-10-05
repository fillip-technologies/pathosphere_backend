<?php

use App\Modules\Network\Enums\FranchiseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outside businesses running branches under the brand (spec §7.1). Created in
 * Phase 1 because branches and users reference it; onboarding comes in Phase 6.
 * `partner_price_list_id` is added in Phase 2 with price lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchises', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('region_id')->constrained()->restrictOnDelete();
            $table->string('franchise_code', 20)->unique();
            $table->string('name', 150);
            $table->string('legal_name', 200);
            $table->string('owner_name', 150);
            $table->string('phone', 15);
            $table->string('email', 150);
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10);
            $table->text('address');
            $table->text('bank_account_no')->nullable()->comment('Encrypted by the application');
            $table->string('bank_ifsc', 11)->nullable();
            $table->money('credit_limit')->default(0);
            $table->money('current_balance')->default(0);
            $table->enumString('status', FranchiseStatus::class);
            $table->dateTime('onboarded_at', 6)->nullable();
            $table->actorColumns(withForeignKeys: false);
            $table->standardTimestamps();
            $table->standardSoftDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchises');
    }
};
