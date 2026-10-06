<?php

namespace Database\Seeders;

use App\Modules\Locker\Models\RecordCategory;
use Illuminate\Database\Seeder;

/**
 * Health-locker record categories (spec §7.11), a lookup list: add
 * categories here, never change the code of one in use.
 */
class RecordCategorySeeder extends Seeder
{
    /** code => [name, icon] in display order */
    private const CATEGORIES = [
        RecordCategory::LAB_REPORT => ['Lab report', 'flask'],
        'prescription' => ['Prescription', 'pill'],
        'discharge_summary' => ['Discharge summary', 'hospital'],
        'imaging' => ['Imaging', 'scan'],
        'vaccination' => ['Vaccination', 'syringe'],
        'bill' => ['Bill', 'receipt'],
        'other' => ['Other', 'file'],
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::CATEGORIES as $code => [$name, $icon]) {
            $order += 10;
            $category = RecordCategory::query()->firstOrNew(['code' => $code]);

            if ($category->exists) {
                continue;
            }

            $category->fill(['name' => $name, 'icon' => $icon, 'sort_order' => $order])->save();
        }
    }
}
