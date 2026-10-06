<?php

namespace App\Modules\Locker\Services;

use App\Modules\Booking\Services\DoctorProfile;
use LogicException;

/** The referring doctor signed in for this request (spec §4: reports shared by consent only). */
final class DoctorViewer
{
    private ?DoctorProfile $doctor = null;

    public function set(DoctorProfile $doctor): void
    {
        $this->doctor = $doctor;
    }

    public function doctor(): DoctorProfile
    {
        return $this->doctor ?? throw new LogicException('No doctor is signed in.');
    }
}
