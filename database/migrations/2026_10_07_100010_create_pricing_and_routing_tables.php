<?php

use App\Modules\Catalogue\Enums\PriceListType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price lists, lab capabilities and routing rules (spec §7.4), plus the
 * price-list columns on branches, franchises and B2B clients that Phase 1
 * could not create yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->enumString('list_type', PriceListType::class);
            $table->boolean('is_default_mrp')->default(false);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->actorColumns();
            $table->standardTimestamps();
            $table->standardSoftDeletes();

            // Exactly one default MRP list per organization.
            $table->uniqueWhere(['organization_id'], '`is_default_mrp` = 1', 'default_mrp_flag', 'price_lists_one_default_mrp');
            $table->check("`is_default_mrp` = 0 or `list_type` = 'mrp'", 'price_lists_default_is_mrp_check');
            $table->check('`valid_to` is null or `valid_to` >= `valid_from`', 'price_lists_validity_check');
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('test_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('package_id')->nullable()->constrained()->restrictOnDelete();
            $table->money('price');
            $table->actorColumns();
            $table->standardTimestamps();

            $table->exactlyOneOf('test_id', 'package_id');
            $table->check('`price` >= 0', 'price_list_items_price_check');
            $table->unique(['price_list_id', 'test_id']);
            $table->unique(['price_list_id', 'package_id']);
        });

        Schema::create('lab_test_capabilities', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('test_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('daily_capacity')->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->unique(['branch_id', 'test_id']);
        });

        Schema::create('test_routing_rules', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('source_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('test_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('processing_branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedSmallInteger('priority')->default(1);
            $table->boolean('is_active')->default(true);
            $table->actorColumns();
            $table->standardTimestamps();

            $table->index(['source_branch_id', 'test_id', 'priority'], 'test_routing_rules_lookup');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignUuid('mrp_price_list_id')->nullable()->after('branch_type')->constrained('price_lists')->restrictOnDelete();
        });

        Schema::table('franchises', function (Blueprint $table) {
            $table->foreignUuid('partner_price_list_id')->nullable()->after('bank_ifsc')->constrained('price_lists')->restrictOnDelete();
        });

        // Phase 1 created no B2B clients, so the required column can be added directly.
        Schema::table('b2b_clients', function (Blueprint $table) {
            $table->foreignUuid('price_list_id')->after('billing_address')->constrained('price_lists')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('b2b_clients', fn (Blueprint $table) => $table->dropConstrainedForeignId('price_list_id'));
        Schema::table('franchises', fn (Blueprint $table) => $table->dropConstrainedForeignId('partner_price_list_id'));
        Schema::table('branches', fn (Blueprint $table) => $table->dropConstrainedForeignId('mrp_price_list_id'));
        Schema::dropIfExists('test_routing_rules');
        Schema::dropIfExists('lab_test_capabilities');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
