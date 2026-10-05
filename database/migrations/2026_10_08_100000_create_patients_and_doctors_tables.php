<?php

use App\Modules\Booking\Enums\AbhaStatus;
use App\Modules\Booking\Enums\ReportDelivery;
use App\Modules\Shared\Enums\Gender;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patients (network-wide identity, spec §7.3 with the ABHA columns of §5.7)
 * and referring doctors (no commission fields, by law: spec §10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('uhid', 20)->unique();
            $table->foreignUuid('registered_branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('salutation', 10)->nullable();
            $table->string('name', 150);
            $table->date('dob')->nullable();
            $table->unsignedSmallInteger('age_years')->nullable();
            $table->enumString('gender', Gender::class);
            $table->string('phone', 15)->index();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('pincode', 6)->nullable();

            // ABHA (spec §5.7). The number is encrypted (spec §10.2); its keyed
            // hash is the searchable, unique "blind index".
            $table->text('abha_number')->nullable();
            $table->sha256('abha_number_hash')->nullable()->unique();
            $table->string('abha_address', 100)->nullable()->unique();
            $table->enumString('abha_status', AbhaStatus::class)->default(AbhaStatus::NotLinked->value);
            $table->boolean('abha_kyc_verified')->default(false);
            $table->dateTime('abha_linked_at', 6)->nullable();
            $table->foreignUuid('abha_linked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('abha_profile_snapshot')->nullable();

            // Consent and messaging preferences (spec §9 rule 3, §10 DPDP).
            $table->string('consent_notice_version', 20)->nullable();
            $table->dateTime('consented_at', 6)->nullable();
            $table->dateTime('whatsapp_opted_in_at', 6)->nullable();

            $table->foreignUuid('guardian_patient_id')->nullable()->constrained('patients')->restrictOnDelete();
            $table->foreignUuid('merged_into_id')->nullable()->constrained('patients')->restrictOnDelete();
            $table->actorColumns();
            $table->standardTimestamps();
            $table->standardSoftDeletes();

            $table->searchableText('name');
            $table->check('`dob` is not null or `age_years` is not null', 'patients_age_known_check');
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('registration_no', 50)->nullable();
            $table->string('specialization', 100)->nullable();
            $table->string('clinic_name', 150)->nullable();
            $table->string('phone', 15)->nullable()->index();
            $table->string('email', 150)->nullable();
            $table->enumString('report_delivery', ReportDelivery::class)->default(ReportDelivery::Whatsapp->value);
            $table->actorColumns();
            $table->standardTimestamps();
            $table->standardSoftDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('patients');
    }
};
