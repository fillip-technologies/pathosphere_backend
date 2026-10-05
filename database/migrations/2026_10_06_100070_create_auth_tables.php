<?php

use App\Modules\Auth\Enums\AccountOwnerType;
use App\Modules\Auth\Enums\AuthMethod;
use App\Modules\Auth\Enums\OtpPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Login identities, refresh-token sessions, OTPs and password resets (spec §7.9). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->enumString('owner_type', AccountOwnerType::class);
            $table->uuid('owner_id');
            $table->string('login_identifier', 150);
            $table->string('password_hash', 255)->nullable();
            $table->enumString('auth_method', AuthMethod::class);
            $table->boolean('mfa_enabled')->default(false);
            $table->text('mfa_secret')->nullable()->comment('Encrypted by the application');
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->dateTime('locked_until', 6)->nullable();
            $table->dateTime('last_login_at', 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->actorColumns();
            $table->standardTimestamps();

            $table->unique(['owner_type', 'owner_id']);
            $table->unique(['owner_type', 'login_identifier']);
        });

        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('account_id')->constrained()->restrictOnDelete();
            $table->sha256('refresh_token_hash')->unique();
            $table->string('device_info', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('expires_at', 6);
            $table->dateTime('revoked_at', 6)->nullable();
            $table->standardTimestamps();
        });

        Schema::create('otp_verifications', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('phone', 15)->index();
            $table->sha256('code_hash');
            $table->enumString('purpose', OtpPurpose::class);
            $table->dateTime('expires_at', 6);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('verified_at', 6)->nullable();
            $table->standardTimestamps();
        });

        Schema::create('password_resets', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('account_id')->constrained()->restrictOnDelete();
            $table->sha256('token_hash')->unique();
            $table->dateTime('expires_at', 6);
            $table->dateTime('used_at', 6)->nullable();
            $table->standardTimestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
        Schema::dropIfExists('otp_verifications');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('accounts');
    }
};
