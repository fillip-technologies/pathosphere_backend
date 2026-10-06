<?php

use App\Modules\Samples\Enums\InventoryCategory;
use App\Modules\Samples\Enums\StockTransferStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch stock and supply between branches (spec §7.7). Additions to the
 * spec: `organization_id` on both tables, and `dispatched_at` /
 * `cancelled_reason` on transfers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('item_code', 30);
            $table->string('name', 150);
            $table->enumString('category', InventoryCategory::class);
            $table->string('unit', 20);
            $table->decimal('quantity', 12, 2);
            $table->decimal('reorder_level', 12, 2)->nullable();
            $table->string('batch_no', 50);
            $table->date('expiry_date')->nullable()->index();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->unique(['branch_id', 'item_code', 'batch_no']);
            $table->check('`quantity` >= 0', 'inventory_items_quantity_check');
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('transfer_no', 30)->unique();
            $table->foreignUuid('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('item_code', 30);
            $table->string('item_name', 150);
            $table->string('batch_no', 50);
            $table->decimal('quantity', 12, 2);
            $table->money('charge_amount')->default(0);
            $table->enumString('status', StockTransferStatus::class);
            $table->dateTime('dispatched_at', 6)->nullable();
            $table->dateTime('received_at', 6)->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->check('`from_branch_id` <> `to_branch_id`', 'stock_transfers_route_check');
            $table->check('`quantity` > 0 and `charge_amount` >= 0', 'stock_transfers_amounts_check');
            $table->index(['from_branch_id', 'status']);
            $table->index(['to_branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('inventory_items');
    }
};
