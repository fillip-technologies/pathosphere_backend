<?php

use App\Modules\Auth\Enums\OtpPurpose;
use App\Modules\Locker\Enums\AccessActorType;
use App\Modules\Locker\Enums\CareContextLinkStatus;
use App\Modules\Locker\Enums\DataTransferStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ABDM milestone M2, our labs as Health Information Providers (spec §5.7).
 *
 * - `abdm_care_contexts` as the spec lists it, plus `organization_id`,
 *   `hip_branch_id` (the lab whose HFR ID ABDM knows the report by),
 *   `medical_record_id` (the locker copy the data is read from and access is
 *   logged against), `link_request_id`, `link_attempts` and `error_code`
 *   (to match ABDM's answer and retry).
 * - `abdm_data_transfers` (addition): one row per health-information
 *   request, so a request is served once and every share is traceable.
 * - `test_parameters.loinc_code` (addition): each result travels as a FHIR
 *   Observation, coded per parameter; `tests.loinc_code` codes the report.
 * - New enum values: `record_access_logs.actor_type` gains `abdm`,
 *   `otp_verifications.purpose` gains `care_context_link`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_parameters', function (Blueprint $table) {
            $table->string('loinc_code', 20)->nullable()->after('code');
        });

        Schema::create('abdm_care_contexts', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('patient_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('report_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('medical_record_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('hip_branch_id')->index()->constrained('branches')->restrictOnDelete();
            $table->string('care_context_reference', 100)->unique();
            $table->string('display_name', 200);
            $table->string('hi_type', 40);
            $table->enumString('link_status', CareContextLinkStatus::class);
            $table->dateTime('linked_at', 6)->nullable();
            $table->string('link_request_id', 64)->nullable()->index();
            $table->unsignedSmallInteger('link_attempts')->default(0);
            $table->string('error_code', 50)->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            // The retry job: unfinished links, oldest first.
            $table->index(['link_status', 'updated_at']);
        });

        Schema::create('abdm_data_transfers', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->string('transaction_id', 64)->unique();
            $table->string('request_id', 64);
            $table->foreignUuid('consent_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('consent_artefact_id', 100)->index();
            $table->string('hip_id', 50);
            $table->dateTime('date_from', 6);
            $table->dateTime('date_to', 6);
            $table->string('data_push_url', 500);
            // The receiver's public key and nonce: public by design, needed to encrypt.
            $table->json('key_material');
            $table->enumString('status', DataTransferStatus::class);
            $table->string('error_code', 50)->nullable();
            $table->unsignedSmallInteger('care_context_count')->default(0);
            $table->dateTime('transferred_at', 6)->nullable();
            $table->standardTimestamps();
        });

        Schema::table('record_access_logs', function (Blueprint $table) {
            $table->refreshEnumCheck('actor_type', AccessActorType::class);
        });

        Schema::table('otp_verifications', function (Blueprint $table) {
            $table->refreshEnumCheck('purpose', OtpPurpose::class);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abdm_data_transfers');
        Schema::dropIfExists('abdm_care_contexts');

        Schema::table('test_parameters', function (Blueprint $table) {
            $table->dropColumn('loinc_code');
        });
    }
};
