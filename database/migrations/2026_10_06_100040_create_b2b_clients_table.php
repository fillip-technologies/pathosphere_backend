<?php

use App\Modules\Network\Enums\B2bClientStatus;
use App\Modules\Network\Enums\B2bClientType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospitals, clinics, labs and corporates buying on credit (spec §7.1).
 * Created in Phase 1 because users reference it; `price_list_id` is added in
 * Phase 2 and client management comes in Phase 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_clients', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('region_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('serviced_by_branch_id')->constrained('branches')->restrictOnDelete();
            $table->enumString('client_type', B2bClientType::class);
            $table->string('client_code', 20)->unique();
            $table->string('name', 200);
            $table->string('gstin', 15)->nullable();
            $table->string('contact_name', 150);
            $table->string('phone', 15);
            $table->string('email', 150);
            $table->text('billing_address');
            $table->money('credit_limit')->default(0);
            $table->unsignedSmallInteger('credit_days')->default(30);
            $table->money('current_balance')->default(0);
            $table->enumString('status', B2bClientStatus::class);
            $table->actorColumns(withForeignKeys: false);
            $table->standardTimestamps();
            $table->standardSoftDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_clients');
    }
};
