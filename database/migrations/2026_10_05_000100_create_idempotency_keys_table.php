<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stored responses for Idempotency-Key retries, kept 24 hours (spec §8.5). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->string('actor_key', 64);
            $table->string('idempotency_key', 100);
            $table->string('request_method', 10);
            $table->string('request_path', 255);
            $table->sha256('request_hash');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->dateTime('expires_at', 6)->index();
            $table->standardTimestamps();

            $table->unique(['actor_key', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
