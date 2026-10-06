<?php

use App\Modules\Locker\Enums\AccessAction;
use App\Modules\Locker\Enums\AccessActorType;
use App\Modules\Locker\Enums\ConsentPurpose;
use App\Modules\Locker\Enums\ConsentStatus;
use App\Modules\Locker\Enums\FamilyRelation;
use App\Modules\Locker\Enums\RecordSource;
use App\Modules\Locker\Enums\ReminderStatus;
use App\Modules\Locker\Enums\ShareStatus;
use App\Modules\Locker\Enums\ShareTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The patient health locker, Engine 17 (spec §7.11): twelve tables hanging
 * off `patients`.
 *
 * Additions to the spec schema:
 * - `record_categories.code`: a stable key the code looks categories up by
 *   (the name is display text and may be reworded);
 * - `medical_records.superseded_by_id`: each released report version gets
 *   its own record; an amended version points at its correction and drops
 *   out of the timeline and trends, while its access history stays intact;
 * - `pathology_results.test_code` / `test_name`: to group results by test;
 * - `record_shares.access_token_hash` is nullable: doctor shares are opened
 *   by the doctor's own sign-in, not a token;
 * - `medical_reminders.sent_at`;
 * - `family_members.deleted_at`: a removed member is not linked again by
 *   the next sign-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_health_profiles', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('patient_id')->unique()->constrained()->restrictOnDelete();
            $table->string('abha_id', 17)->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->text('allergies')->nullable();
            $table->text('chronic_conditions')->nullable();
            $table->text('health_summary')->nullable();
            $table->actorColumns();
            $table->standardTimestamps();
        });

        Schema::create('family_members', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('patient_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('member_patient_id')->nullable()->index()->constrained('patients')->restrictOnDelete();
            $table->string('name', 150);
            $table->enumString('relation', FamilyRelation::class);
            $table->date('dob')->nullable();
            $table->actorColumns();
            $table->standardTimestamps();
            $table->standardSoftDeletes();

            // NULL members (dependants without a UHID yet) never collide.
            $table->unique(['patient_id', 'member_patient_id']);
            $table->check('`member_patient_id` is null or `member_patient_id` <> `patient_id`', 'family_members_not_self_check');
        });

        Schema::create('record_categories', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->string('code', 32)->unique();
            $table->string('name', 60)->unique();
            $table->string('icon', 30)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->actorColumns();
            $table->standardTimestamps();
        });

        Schema::create('medical_records', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('patient_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('category_id')->index()->constrained('record_categories')->restrictOnDelete();
            $table->enumString('source', RecordSource::class);
            $table->date('record_date')->index();
            $table->string('title', 200);
            $table->string('provider_facility', 200)->nullable();
            $table->foreignUuid('superseded_by_id')->nullable()->index()->constrained('medical_records')->restrictOnDelete();
            $table->actorColumns();
            $table->standardTimestamps();

            // The timeline: one patient's current records by date.
            $table->index(['patient_id', 'superseded_by_id', 'record_date'], 'medical_records_patient_timeline_index');
        });

        Schema::create('medical_documents', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('medical_record_id')->constrained()->restrictOnDelete();
            $table->string('file_path', 500);
            $table->string('mime_type', 60);
            $table->unsignedInteger('size_bytes');
            $table->sha256('checksum');
            $table->unsignedSmallInteger('version');
            $table->dateTime('uploaded_at', 6);
            $table->actorColumns();
            $table->standardTimestamps();

            // New versions are new rows; nothing is overwritten.
            $table->unique(['medical_record_id', 'version']);
        });

        Schema::create('pathology_reports', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('medical_record_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('report_id')->unique()->constrained()->restrictOnDelete();
            $table->string('lab_name', 150);
            $table->actorColumns();
            $table->standardTimestamps();
        });

        Schema::create('pathology_results', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('pathology_report_id')->index()->constrained()->restrictOnDelete();
            $table->string('test_code', 30);
            $table->string('test_name', 150);
            $table->string('parameter_code', 30)->index();
            $table->string('parameter_name', 150);
            $table->string('value', 255)->nullable();
            $table->decimal('value_numeric', 14, 4)->nullable();
            $table->string('unit', 30)->nullable();
            $table->string('reference_range', 100)->nullable();
            $table->string('flag', 32)->nullable();
            $table->standardTimestamps();
        });

        Schema::create('external_health_records', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('patient_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('medical_record_id')->unique()->constrained()->restrictOnDelete();
            $table->enumString('source', RecordSource::class);
            $table->string('external_id', 150);
            $table->string('care_context_ref', 150)->nullable();
            $table->dateTime('fetched_at', 6);
            $table->actorColumns();
            $table->standardTimestamps();

            $table->unique(['source', 'external_id']);
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('patient_id')->index()->constrained()->restrictOnDelete();
            $table->string('consent_artefact_id', 100)->nullable()->unique();
            $table->string('requester', 150);
            $table->enumString('purpose', ConsentPurpose::class);
            $table->json('scope');
            $table->enumString('status', ConsentStatus::class);
            $table->dateTime('granted_at', 6)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('record_shares', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('medical_record_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('consent_id')->index()->constrained()->restrictOnDelete();
            $table->enumString('shared_with_type', ShareTarget::class);
            $table->string('shared_with_ref', 150);
            $table->sha256('access_token_hash')->nullable()->unique();
            $table->dateTime('expires_at', 6);
            $table->enumString('status', ShareStatus::class);
            $table->actorColumns();
            $table->standardTimestamps();

            // A doctor's list of what is shared with them.
            $table->index(['shared_with_type', 'shared_with_ref', 'status'], 'record_shares_recipient_index');
            $table->check("`shared_with_type` = 'doctor' or `access_token_hash` is not null", 'record_shares_token_check');
        });

        Schema::create('record_access_logs', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('medical_record_id')->index()->constrained()->restrictOnDelete();
            $table->enumString('actor_type', AccessActorType::class);
            $table->uuid('actor_id')->nullable();
            $table->enumString('action', AccessAction::class);
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('accessed_at', 6)->index();
        });

        Schema::create('medical_reminders', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('patient_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('medical_record_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->dateTime('remind_at', 6);
            $table->text('message');
            $table->enumString('status', ReminderStatus::class);
            $table->dateTime('sent_at', 6)->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->index(['status', 'remind_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_reminders');
        Schema::dropIfExists('record_access_logs');
        Schema::dropIfExists('record_shares');
        Schema::dropIfExists('consents');
        Schema::dropIfExists('external_health_records');
        Schema::dropIfExists('pathology_results');
        Schema::dropIfExists('pathology_reports');
        Schema::dropIfExists('medical_documents');
        Schema::dropIfExists('medical_records');
        Schema::dropIfExists('record_categories');
        Schema::dropIfExists('family_members');
        Schema::dropIfExists('patient_health_profiles');
    }
};
