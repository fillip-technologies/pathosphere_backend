<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nightly per-branch summary that dashboards read (spec §11 observability 4:
 * heavy reports never slow the front desk). Not in the spec's data
 * dictionary; rebuilt from the business tables, so it can be recomputed.
 * Region and franchise are copied from the branch for roll-ups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_branch_metrics', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->date('metric_date');
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('region_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('orders_booked')->default(0);
            $table->unsignedInteger('orders_cancelled')->default(0);
            $table->unsignedInteger('tests_ordered')->default(0);
            $table->money('gross_billing')->default(0);
            $table->money('collected')->default(0);
            $table->unsignedInteger('samples_rejected')->default(0);
            $table->unsignedInteger('reports_released')->default(0);
            $table->unsignedInteger('tat_breaches')->default(0);
            $table->standardTimestamps();

            $table->unique(['branch_id', 'metric_date']);
            $table->index(['organization_id', 'metric_date']);
            $table->index(['region_id', 'metric_date']);
            $table->index(['franchise_id', 'metric_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_branch_metrics');
    }
};
