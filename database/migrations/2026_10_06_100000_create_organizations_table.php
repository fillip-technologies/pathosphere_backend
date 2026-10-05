<?php

use App\Modules\Network\Enums\OrganizationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The brand / head office (spec §7.1). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->string('name', 150);
            $table->string('legal_name', 200);
            $table->string('gstin', 15)->nullable()->unique();
            $table->string('pan', 10)->nullable();
            $table->string('cin', 21)->nullable();
            $table->text('hq_address');
            $table->string('logo_path', 500)->nullable();
            $table->json('settings')->default(new Expression('(json_object())'));
            $table->enumString('status', OrganizationStatus::class);
            $table->actorColumns(withForeignKeys: false);
            $table->standardTimestamps();
            $table->standardSoftDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
