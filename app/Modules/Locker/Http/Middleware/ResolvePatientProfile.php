<?php

namespace App\Modules\Locker\Http\Middleware;

use App\Modules\Auth\Services\PersonAccounts;
use App\Modules\Auth\Services\SignedInPerson;
use App\Modules\Booking\Services\PeopleDirectory;
use App\Modules\Locker\Errors\LockerError;
use App\Modules\Locker\Services\FamilyService;
use App\Modules\Locker\Services\PatientViewer;
use App\Modules\Shared\Context\Actor;
use App\Modules\Shared\Context\CurrentActor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `person:patient`. Works out whose records the request is about:
 * the account holder, or a family member named in the `X-Patient-Id` header
 * (spec §7.9 family switch). Anyone else's ID is a 404.
 */
final class ResolvePatientProfile
{
    public const PROFILE_HEADER = 'X-Patient-Id';

    public function __construct(
        private readonly SignedInPerson $person,
        private readonly PersonAccounts $accounts,
        private readonly PeopleDirectory $people,
        private readonly FamilyService $family,
        private readonly PatientViewer $viewer,
        private readonly CurrentActor $currentActor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $account = $this->person->account();
        $holder = $this->people->patient($account->ownerId) ?? throw LockerError::patientUnavailable();

        if ($holder->id !== $account->ownerId) {
            $this->accounts->moveTo($account, $holder->id);
        }

        $requested = $request->header(self::PROFILE_HEADER);
        $profile = $holder;

        if (is_string($requested) && $requested !== '' && $requested !== $holder->id) {
            $profile = Str::isUuid($requested) ? $this->family->switchableProfile($holder, $requested) : null;

            if ($profile === null) {
                throw LockerError::profileNotFound();
            }
        }

        $this->viewer->set($holder, $profile, $this->people->idsMergedInto($profile->id));
        $this->currentActor->set(Actor::external($holder->organizationId));

        return $next($request);
    }
}
