<?php

namespace App\Modules\Locker\Models;

use App\Modules\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;

/**
 * A file behind a record (spec §7.11). A new upload adds the next version;
 * nothing is overwritten.
 *
 * @property string $id
 * @property string $medical_record_id
 * @property string $file_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum
 * @property int $version
 * @property CarbonImmutable $uploaded_at
 */
final class MedicalDocument extends BaseModel
{
    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'version' => 'integer', 'uploaded_at' => 'immutable_datetime'];
    }
}
