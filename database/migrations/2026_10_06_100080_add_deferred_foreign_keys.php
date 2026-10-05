<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign keys that could not exist when their tables were created: actor
 * columns on tables `users` depends on, and the Phase 0 shared tables.
 */
return new class extends Migration
{
    private const ACTOR_TABLES = ['organizations', 'regions', 'franchises', 'branches', 'b2b_clients', 'roles'];

    public function up(): void
    {
        foreach (self::ACTOR_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('number_sequences', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('number_sequences', fn (Blueprint $table) => $table->dropForeign(['organization_id']));
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['user_id']);
        });

        foreach (self::ACTOR_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['created_by']);
                $table->dropForeign(['updated_by']);
            });
        }
    }
};
