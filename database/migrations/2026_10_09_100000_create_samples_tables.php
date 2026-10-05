<?php

use App\Modules\Samples\Enums\ManifestItemCondition;
use App\Modules\Samples\Enums\ManifestStatus;
use App\Modules\Samples\Enums\SampleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Samples, the order items each container serves, and manifests (spec §7.6).
 *
 * Additions to the spec schema: `organization_id` on samples and manifests
 * (organization scoping), `samples.current_branch_id` (where the container is
 * now, so a hub lab can forward a sample it does not test), `stable_until`
 * and the alert timestamps used by the transit and missing-sample monitors,
 * and `order_items.recollection_of_item_id` linking a free redraw to the line
 * it replaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('samples', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->string('barcode', 30)->unique();
            $table->string('sample_type', 50);
            $table->string('container_type', 50);
            $table->foreignUuid('collected_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('processing_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('current_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('collected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('collection_datetime', 6)->nullable();
            $table->dateTime('stable_until', 6)->nullable();
            $table->dateTime('received_at', 6)->nullable();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->enumString('status', SampleStatus::class);
            $table->string('rejection_reason', 50)->nullable();
            $table->text('rejection_note')->nullable();
            $table->foreignUuid('recollection_of_id')->nullable()->constrained('samples')->restrictOnDelete();
            $table->string('storage_location', 50)->nullable();
            $table->date('discard_after')->nullable();
            $table->dateTime('delay_alerted_at', 6)->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->index(['processing_branch_id', 'status']);
            $table->index(['current_branch_id', 'status']);
            $table->index(['collected_branch_id', 'status']);
            $table->index(['status', 'stable_until']);
        });

        Schema::create('sample_order_items', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('sample_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_item_id')->index()->constrained()->restrictOnDelete();
            $table->standardTimestamps();

            $table->unique(['sample_id', 'order_item_id']);
        });

        Schema::create('sample_transfers', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('manifest_no', 30)->unique();
            $table->foreignUuid('from_branch_id')->index()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('to_branch_id')->index()->constrained('branches')->restrictOnDelete();
            // Nullable (spec: not null): an open bag fills up before anyone dispatches it.
            $table->foreignUuid('dispatched_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('dispatched_at', 6)->nullable();
            $table->string('courier_name', 100)->nullable();
            $table->boolean('temperature_ok')->nullable();
            $table->decimal('dispatch_temp_c', 4, 1)->nullable();
            $table->decimal('receipt_temp_c', 4, 1)->nullable();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('received_at', 6)->nullable();
            $table->enumString('status', ManifestStatus::class);
            $table->dateTime('missing_flagged_at', 6)->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->check('`from_branch_id` <> `to_branch_id`', 'sample_transfers_route_check');
            // One open bag per route, so collected samples always join the same one.
            $table->uniqueWhere(['from_branch_id', 'to_branch_id'], "`status` = 'created'", 'is_open', 'sample_transfers_open_route_unique');
            $table->index(['status', 'received_at']);
        });

        Schema::create('sample_transfer_items', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('transfer_id')->constrained('sample_transfers')->cascadeOnDelete();
            $table->foreignUuid('sample_id')->constrained()->restrictOnDelete();
            $table->enumString('condition', ManifestItemCondition::class)->default(ManifestItemCondition::Pending->value);
            $table->string('rejection_reason', 50)->nullable();
            $table->dateTime('scanned_at', 6)->nullable();
            $table->standardTimestamps();

            $table->unique(['transfer_id', 'sample_id']);
            $table->index('sample_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignUuid('recollection_of_item_id')->nullable()->after('parent_item_id')
                ->constrained('order_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recollection_of_item_id');
        });
        Schema::dropIfExists('sample_transfer_items');
        Schema::dropIfExists('sample_transfers');
        Schema::dropIfExists('sample_order_items');
        Schema::dropIfExists('samples');
    }
};
