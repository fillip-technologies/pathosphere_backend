<?php

use App\Modules\Catalogue\Enums\ResultType;
use App\Modules\Catalogue\Enums\SigningDiscipline;
use App\Modules\Catalogue\Enums\StorageTemperature;
use App\Modules\Shared\Enums\Gender;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Departments, tests, parameters, reference ranges and packages (spec §7.2, §7.4). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->enumString('signing_discipline', SigningDiscipline::class);
            $table->smallInteger('report_order')->default(0);
            $table->actorColumns();
            $table->standardTimestamps();
            $table->standardSoftDeletes();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('tests', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 200);
            $table->string('short_name', 50)->nullable();
            $table->string('loinc_code', 20)->nullable();
            $table->string('sample_type', 50);
            $table->string('container_type', 50);
            $table->decimal('sample_volume_ml', 5, 2)->nullable();
            $table->enumString('storage_temp', StorageTemperature::class)->nullable();
            $table->unsignedSmallInteger('stability_hours')->nullable();
            $table->unsignedSmallInteger('tat_hours');
            $table->string('method', 100)->nullable();
            $table->text('patient_instructions')->nullable();
            $table->money('base_price');
            $table->boolean('is_outsourced_only')->default(false);
            $table->boolean('is_active')->default(true);
            $table->actorColumns();
            $table->standardTimestamps();
            $table->standardSoftDeletes();

            $table->unique(['organization_id', 'code']);
            $table->searchableText('name');
        });

        Schema::create('test_parameters', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('test_id')->constrained()->restrictOnDelete();
            $table->string('parameter_name', 150);
            $table->string('code', 30);
            $table->string('unit', 30)->nullable();
            $table->enumString('result_type', ResultType::class);
            $table->unsignedSmallInteger('decimal_places')->nullable();
            $table->json('options')->nullable();
            $table->text('formula')->nullable();
            $table->smallInteger('display_order')->default(0);
            $table->actorColumns();
            $table->standardTimestamps();
            $table->standardSoftDeletes();

            $table->unique(['test_id', 'code']);
        });

        Schema::create('test_reference_ranges', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('test_parameter_id')->constrained()->cascadeOnDelete();
            $table->enumString('gender', Gender::class)->nullable();
            $table->unsignedInteger('age_min_days')->nullable();
            $table->unsignedInteger('age_max_days')->nullable();
            $table->decimal('ref_low', 12, 4)->nullable();
            $table->decimal('ref_high', 12, 4)->nullable();
            $table->decimal('critical_low', 12, 4)->nullable();
            $table->decimal('critical_high', 12, 4)->nullable();
            $table->string('display_text', 100)->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->check('`age_min_days` is null or `age_max_days` is null or `age_min_days` <= `age_max_days`', 'test_reference_ranges_age_check');
            $table->check('`ref_low` is null or `ref_high` is null or `ref_low` <= `ref_high`', 'test_reference_ranges_ref_check');
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->actorColumns();
            $table->standardTimestamps();
            $table->standardSoftDeletes();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('package_tests', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('package_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('test_id')->constrained()->restrictOnDelete();
            $table->standardTimestamps();

            $table->unique(['package_id', 'test_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_tests');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('test_reference_ranges');
        Schema::dropIfExists('test_parameters');
        Schema::dropIfExists('tests');
        Schema::dropIfExists('departments');
    }
};
