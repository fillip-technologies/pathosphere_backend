<?php

use App\Modules\Booking\Enums\AbdmDirection;
use App\Modules\Booking\Enums\AbdmRequestStatus;
use App\Modules\Shared\Notifications\NotificationChannel;
use App\Modules\Shared\Notifications\NotificationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ABDM call log (spec §5.7) and message templates / send log (spec §7.10). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abdm_requests', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('patient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->enumString('direction', AbdmDirection::class);
            $table->string('api_name', 80);
            $table->string('request_id', 64)->unique();
            $table->string('txn_id', 64)->nullable()->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->enumString('status', AbdmRequestStatus::class);
            $table->string('error_code', 50)->nullable();
            $table->json('payload_masked')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->standardTimestamps();

            $table->index('created_at');
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('event_key', 60);
            $table->enumString('channel', NotificationChannel::class);
            $table->string('language', 5)->default('en');
            $table->string('subject', 200)->nullable();
            $table->text('body_template');
            $table->string('provider_template_id', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->actorColumns();
            $table->standardTimestamps();

            $table->unique(['organization_id', 'event_key', 'channel', 'language'], 'notification_templates_unique');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('recipient_type', 32);
            $table->uuid('recipient_id');
            $table->foreignUuid('template_id')->constrained('notification_templates')->restrictOnDelete();
            $table->enumString('channel', NotificationChannel::class);
            $table->string('destination', 150);
            $table->json('payload');
            $table->string('provider_message_id', 100)->nullable()->index();
            $table->enumString('status', NotificationStatus::class);
            $table->text('error')->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->standardTimestamps();

            $table->index(['recipient_type', 'recipient_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('abdm_requests');
    }
};
