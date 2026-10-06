<?php

namespace App\Modules\Locker\Models;

use App\Modules\Shared\Models\BaseModel;

/**
 * One-to-one health extension of a patient (spec §7.11), kept by the
 * patient themselves in the app.
 *
 * @property string $id
 * @property string $patient_id
 * @property string|null $abha_id
 * @property string|null $blood_group
 * @property string|null $allergies
 * @property string|null $chronic_conditions
 * @property string|null $health_summary
 */
final class PatientHealthProfile extends BaseModel {}
