<?php

use App\Modules\Catalogue\Enums\SigningDiscipline;
use App\Modules\Lab\Enums\ReportStatus;
use App\Modules\Lab\Enums\ResultFlag;
use App\Modules\Lab\Enums\ResultSource;
use App\Modules\Lab\Enums\WorklistStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signatories, lab work, results and reports (spec §7.2, §7.6).
 *
 * Additions to the spec schema:
 * - `organization_id` on every table (organization scoping);
 * - `signatories.signing_discipline`: the discipline the qualification covers,
 *   checked against the department's instead of parsing free text;
 * - `worklist_entries`: one row per test at the lab that runs it, opened when
 *   its sample is received there. The lab's worklist, the run counter and the
 *   TAT clock live here;
 * - `lab_results.worklist_entry_id` and `processing_branch_id` (lab scoping);
 * - `reports` are per order *and processing lab* (spec: per order), because
 *   each lab's section is signed by that lab's signatories and printed with
 *   its NABL details; booking-side scope columns are copied from the order;
 * - `report_signatures.revoked_at`: a signature is voided, not deleted, when
 *   its department's results change before release;
 * - `interface_agents`: per-lab API keys for the analyser interface (spec §8.1);
 * - `b2b_clients.withhold_reports_when_overdue`: the per-client withholding
 *   policy (spec §12 open decision: B2B only, per client).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatories', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained()->restrictOnDelete();
            $table->enumString('signing_discipline', SigningDiscipline::class);
            $table->string('qualification', 100);
            $table->string('council_name', 100);
            $table->string('registration_no', 50);
            $table->string('hpr_id', 50)->nullable();
            $table->string('signature_image_path', 500);
            $table->date('valid_till')->nullable();
            $table->boolean('is_active')->default(true);
            $table->actorColumns();
            $table->standardTimestamps();
            $table->standardSoftDeletes();

            // Spec: U (user_id, branch_id, department_id), among rows not deleted.
            $table->uniqueWhere(['user_id', 'branch_id', 'department_id'], '`deleted_at` is null', 'is_current', 'signatories_user_lab_department_unique');
            $table->index(['branch_id', 'department_id']);
        });

        Schema::create('worklist_entries', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('sample_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('processing_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('test_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('current_run')->default(1);
            $table->dateTime('due_at', 6)->nullable();
            $table->enumString('status', WorklistStatus::class);
            $table->dateTime('tat_alerted_at', 6)->nullable();
            $table->actorColumns();
            $table->standardTimestamps();

            // A test is worked on at one lab at a time.
            $table->uniqueWhere(['order_item_id'], "`status` <> 'withdrawn'", 'is_open', 'worklist_entries_open_item_unique');
            $table->index(['processing_branch_id', 'status', 'department_id'], 'worklist_entries_lab_status_department_index');
            $table->index(['order_id', 'processing_branch_id']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('lab_results', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('worklist_entry_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('processing_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('sample_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('order_item_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('test_parameter_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('run_no')->default(1);
            $table->string('value', 255)->nullable();
            $table->decimal('value_numeric', 14, 4)->nullable();
            $table->string('unit', 30)->nullable();
            $table->string('ref_range_text', 100)->nullable();
            $table->enumString('flag', ResultFlag::class)->nullable();
            $table->boolean('is_critical')->default(false);
            $table->string('instrument', 100)->nullable();
            $table->enumString('source', ResultSource::class);
            $table->text('comment')->nullable();
            $table->foreignUuid('entered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('entered_at', 6);
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('verified_at', 6)->nullable();
            $table->boolean('is_final')->default(false);
            $table->actorColumns();
            $table->standardTimestamps();

            $table->unique(['order_item_id', 'test_parameter_id', 'run_no']);
            $table->index(['worklist_entry_id', 'run_no']);
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('patient_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('b2b_client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('processing_branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->enumString('status', ReportStatus::class);
            $table->boolean('is_partial')->default(false);
            $table->text('amendment_reason')->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->sha256('pdf_sha256')->nullable();
            $table->string('qr_code', 64)->unique();
            $table->dateTime('released_at', 6)->nullable();
            $table->foreignUuid('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->actorColumns();
            $table->standardTimestamps();

            $table->unique(['order_id', 'processing_branch_id', 'version']);
            // One live version per order and lab; superseded versions are `amended`.
            $table->uniqueWhere(['order_id', 'processing_branch_id'], "`status` <> 'amended'", 'is_current', 'reports_current_version_unique');
            $table->index(['processing_branch_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['b2b_client_id', 'status']);
            $table->check('`version` = 1 or `amendment_reason` is not null', 'reports_amendment_reason_check');
        });

        Schema::create('report_signatures', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('report_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('signatory_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('department_id')->constrained()->restrictOnDelete();
            $table->dateTime('signed_at', 6);
            $table->string('signer_ip', 45)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->standardTimestamps();

            $table->uniqueWhere(['report_id', 'department_id'], '`revoked_at` is null', 'is_valid', 'report_signatures_department_unique');
        });

        Schema::create('interface_agents', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->index()->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('key_prefix', 16);
            $table->sha256('key_hash')->unique();
            $table->json('allowed_ips');
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_seen_at', 6)->nullable();
            $table->actorColumns();
            $table->standardTimestamps();
        });

        Schema::table('b2b_clients', function (Blueprint $table) {
            $table->boolean('withhold_reports_when_overdue')->default(false)->after('current_balance');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_clients', function (Blueprint $table) {
            $table->dropColumn('withhold_reports_when_overdue');
        });
        Schema::dropIfExists('interface_agents');
        Schema::dropIfExists('report_signatures');
        Schema::dropIfExists('reports');
        Schema::dropIfExists('lab_results');
        Schema::dropIfExists('worklist_entries');
        Schema::dropIfExists('signatories');
    }
};
