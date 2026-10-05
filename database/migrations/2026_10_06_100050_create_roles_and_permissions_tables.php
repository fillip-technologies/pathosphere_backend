<?php

use App\Modules\Shared\Scoping\ScopeLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** RBAC (spec §7.2, §7.9). Permissions are seeded from the Permission enum. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->enumString('scope_level', ScopeLevel::class);
            $table->boolean('is_system')->default(false);
            $table->string('description', 255)->nullable();
            $table->actorColumns(withForeignKeys: false);
            $table->standardTimestamps();
            $table->standardSoftDeletes();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->string('name', 80)->unique();
            $table->string('module', 40);
            $table->string('description', 255);
            $table->standardTimestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained()->cascadeOnDelete();
            $table->standardTimestamps();

            $table->unique(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
