<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail (spec §7.10). Foreign keys to organizations and
 * users are added in Phase 1, when those tables exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->uuid('organization_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('franchise_id')->nullable()->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->string('action', 60);
            $table->string('entity_type', 60);
            $table->uuid('entity_id');
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->standardTimestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
