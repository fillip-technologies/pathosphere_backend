<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum short-lived access tokens (spec §8.1). Token owners are UUID models
 * (`accounts`), and datetime columns avoid the 2038 limit of `timestamp`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->dateTime('last_used_at', 6)->nullable();
            $table->dateTime('expires_at', 6)->nullable()->index();
            $table->datetimes(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
