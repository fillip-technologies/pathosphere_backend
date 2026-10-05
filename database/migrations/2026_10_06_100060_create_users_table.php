<?php

use App\Modules\Auth\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every staff member (spec §7.2). Credentials live in `accounts`. The scope
 * column matching the role's scope level is enforced by the user service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('role_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('region_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('b2b_client_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('employee_code', 20)->nullable();
            $table->string('name', 150);
            $table->string('email', 150)->nullable()->unique();
            $table->string('phone', 15)->unique();
            $table->enumString('status', UserStatus::class);
            $table->actorColumns();
            $table->standardTimestamps();
            $table->standardSoftDeletes();

            $table->unique(['organization_id', 'employee_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
