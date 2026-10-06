<?php

namespace App\Modules\Locker\Http\Middleware;

use App\Modules\Auth\Errors\AuthError;
use App\Modules\Auth\Services\SignedInPerson;
use App\Modules\Booking\Services\PeopleDirectory;
use App\Modules\Locker\Services\DoctorViewer;
use App\Modules\Shared\Context\Actor;
use App\Modules\Shared\Context\CurrentActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs after `person:doctor`: loads the referring doctor behind the login. */
final class ResolveDoctor
{
    public function __construct(
        private readonly SignedInPerson $person,
        private readonly PeopleDirectory $people,
        private readonly DoctorViewer $viewer,
        private readonly CurrentActor $currentActor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $doctor = $this->people->doctor($this->person->account()->ownerId) ?? throw AuthError::accountDisabled();

        $this->viewer->set($doctor);
        $this->currentActor->set(Actor::external($doctor->organizationId));

        return $next($request);
    }
}
