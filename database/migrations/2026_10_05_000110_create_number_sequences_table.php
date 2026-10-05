<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counters for human-readable numbers (spec §6.9). The organization foreign
 * key is added in Phase 1, when `organizations` exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->uuid('organization_id');
            $table->string('series_key', 60);
            $table->string('financial_year', 7);
            $table->unsignedBigInteger('next_value');
            $table->standardTimestamps();

            $table->unique(['organization_id', 'series_key', 'financial_year'], 'number_sequences_series_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
