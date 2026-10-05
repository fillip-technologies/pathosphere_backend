<?php

use App\Modules\Network\Enums\RegionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Zone / state / city tree used to scope regional managers (spec §7.1). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->uuidPrimary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('parent_region_id')->nullable()->constrained('regions')->restrictOnDelete();
            $table->string('name', 100);
            $table->enumString('region_type', RegionType::class);
            $table->actorColumns(withForeignKeys: false);
            $table->standardTimestamps();
            $table->standardSoftDeletes();

            $table->unique(['organization_id', 'parent_region_id', 'name'], 'regions_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
