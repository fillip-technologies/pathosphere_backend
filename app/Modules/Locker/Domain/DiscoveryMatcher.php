<?php

namespace App\Modules\Locker\Domain;

use App\Modules\Booking\Services\AbhaDiscoveryCandidate;
use App\Modules\Locker\Contracts\Abdm\DiscoveryRequested;
use App\Modules\Shared\Enums\Gender;

/**
 * Decides which of our patients a person searching from their ABHA app is
 * (user-initiated linking). Showing someone else's reports is the harm to
 * avoid, so the rule is strict and an unclear case finds no one:
 *
 * 1. The ABHA number ABDM verified is linked to exactly that patient at our
 *    desk: a match.
 * 2. Otherwise the verified mobile, with the same gender, a year of birth no
 *    more than a year apart (when both are known) and the same name (case,
 *    dots, titles and middle names aside).
 * 3. Several such patients (a family sharing a phone): a UHID the person
 *    typed may pick one; else no one is chosen.
 *
 * Linking still needs the code sent to the phone on our record.
 */
final class DiscoveryMatcher
{
    private const TITLES = ['mr', 'mrs', 'ms', 'miss', 'dr', 'shri', 'sri', 'smt', 'master', 'baby'];

    /** @param  list<AbhaDiscoveryCandidate>  $candidates */
    public static function match(DiscoveryRequested $request, array $candidates): DiscoveryMatch
    {
        foreach ($candidates as $candidate) {
            if ($request->verifiedAbhaNumber !== null && $candidate->abhaNumberMatches) {
                return DiscoveryMatch::found($candidate, ['abha_number']);
            }
        }

        if ($request->verifiedMobile === null) {
            return DiscoveryMatch::notFound();
        }

        $matches = array_values(array_filter(
            $candidates,
            fn (AbhaDiscoveryCandidate $candidate) => $candidate->phoneMatches && self::sameDemographics($request, $candidate),
        ));

        if (count($matches) > 1 && $request->unverifiedUhid !== null) {
            $byUhid = array_values(array_filter($matches, fn (AbhaDiscoveryCandidate $candidate) => strcasecmp($candidate->uhid, trim($request->unverifiedUhid ?? '')) === 0));

            if (count($byUhid) === 1) {
                return DiscoveryMatch::found($byUhid[0], ['mobile', 'uhid']);
            }
        }

        return match (count($matches)) {
            0 => DiscoveryMatch::notFound(),
            1 => DiscoveryMatch::found($matches[0], ['mobile']),
            default => DiscoveryMatch::ambiguous(),
        };
    }

    /** ABDM's M, F or O. */
    public static function abdmGender(Gender $gender): string
    {
        return match ($gender) {
            Gender::Male => 'M',
            Gender::Female => 'F',
            Gender::Other, Gender::Unknown => 'O',
        };
    }

    private static function sameDemographics(DiscoveryRequested $request, AbhaDiscoveryCandidate $candidate): bool
    {
        if (self::abdmGender($candidate->gender) !== strtoupper($request->gender)) {
            return false;
        }

        if ($request->yearOfBirth !== null && $candidate->yearOfBirth !== null && abs($request->yearOfBirth - $candidate->yearOfBirth) > 1) {
            return false;
        }

        return self::sameName($request->name, $candidate->name);
    }

    /** Equal names, or the same first and last name when one has a middle name the other lacks. */
    public static function sameName(string $a, string $b): bool
    {
        $first = self::nameWords($a);
        $second = self::nameWords($b);

        if ($first === [] || $second === []) {
            return false;
        }

        if ($first === $second) {
            return true;
        }

        return count($first) > 1 && count($second) > 1
            && $first[0] === $second[0]
            && $first[array_key_last($first)] === $second[array_key_last($second)];
    }

    /** @return list<string> */
    private static function nameWords(string $name): array
    {
        $words = preg_split('/[\s.]+/', mb_strtolower(trim($name))) ?: [];

        return array_values(array_filter($words, fn (string $word) => $word !== '' && ! in_array($word, self::TITLES, true)));
    }
}
