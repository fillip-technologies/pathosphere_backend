<?php

use App\Modules\Booking\Enums\HomeCollectionStatus;
use App\Modules\Booking\Enums\OrderItemStatus;
use App\Modules\Booking\Enums\OrderSource;
use App\Modules\Booking\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Orders, their priced items and home collections (spec §7.5). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('order_no', 30)->unique();
            $table->foreignUuid('patient_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('b2b_client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('doctor_id')->nullable()->constrained()->restrictOnDelete();
            $table->enumString('order_source', OrderSource::class);
            $table->string('external_ref', 50)->nullable();
            $table->text('clinical_notes')->nullable();
            $table->dateTime('order_date', 6);
            $table->enumString('status', OrderStatus::class);
            $table->text('cancelled_reason')->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->index(['branch_id', 'order_date']);
            $table->index(['organization_id', 'order_date']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('test_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('package_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('parent_item_id')->nullable()->constrained('order_items')->restrictOnDelete();
            $table->foreignUuid('processing_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->money('mrp_price');
            $table->money('partner_price')->default(0);
            $table->money('discount')->default(0);
            $table->string('discount_reason', 100)->nullable();
            $table->money('net_price');
            $table->dateTime('due_at', 6)->nullable();
            $table->enumString('status', OrderItemStatus::class);
            $table->actorColumns();
            $table->standardTimestamps();

            $table->exactlyOneOf('test_id', 'package_id');
            $table->check('`net_price` = `mrp_price` - `discount` and `discount` >= 0', 'order_items_net_price_check');
            $table->index(['processing_branch_id', 'status']);
        });

        Schema::create('home_collections', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('phlebotomist_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('address');
            $table->string('pincode', 6);
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->dateTime('slot_start', 6);
            $table->dateTime('slot_end', 6);
            $table->money('collection_charge')->default(0);
            $table->enumString('status', HomeCollectionStatus::class);
            $table->text('status_note')->nullable();
            $table->dateTime('collected_at', 6)->nullable();
            $table->decimal('collected_lat', 9, 6)->nullable();
            $table->decimal('collected_lng', 9, 6)->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->index(['branch_id', 'slot_start']);
            $table->index(['phlebotomist_id', 'slot_start']);
            $table->check('`slot_end` > `slot_start`', 'home_collections_slot_check');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_collections');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
