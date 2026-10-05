<?php

namespace App\Modules\Catalogue\Domain;

/**
 * Chooses the lab that will run a test booked at a source branch
 * (spec §7.4 routing algorithm):
 *
 *   1. rules for this exact test, lowest priority number first;
 *   2. then the branch's default rules (no test), same order;
 *   3. then the source branch itself;
 *
 * and the first candidate that is an operating lab with an active capability
 * for the test wins. No winner means the test cannot be booked here.
 */
final class ProcessingBranchResolver
{
    /**
     * @param  list<RoutingRuleEntry>  $rules  active rules of the source branch
     * @param  array<string, array<string, true>>  $capableLabs  operating lab ID => test IDs it can run now
     */
    public static function resolve(string $sourceBranchId, string $testId, array $rules, array $capableLabs): ?string
    {
        foreach (self::candidates($sourceBranchId, $testId, $rules) as $branchId) {
            if (isset($capableLabs[$branchId][$testId])) {
                return $branchId;
            }
        }

        return null;
    }

    /**
     * @param  list<RoutingRuleEntry>  $rules
     * @return list<string> branch IDs in the order they are tried
     */
    private static function candidates(string $sourceBranchId, string $testId, array $rules): array
    {
        $byPriority = fn (RoutingRuleEntry $a, RoutingRuleEntry $b): int => $a->priority <=> $b->priority;

        $testRules = array_filter($rules, fn (RoutingRuleEntry $rule): bool => $rule->testId === $testId);
        $defaultRules = array_filter($rules, fn (RoutingRuleEntry $rule): bool => $rule->testId === null);
        usort($testRules, $byPriority);
        usort($defaultRules, $byPriority);

        return [
            ...array_map(fn (RoutingRuleEntry $rule) => $rule->processingBranchId, $testRules),
            ...array_map(fn (RoutingRuleEntry $rule) => $rule->processingBranchId, $defaultRules),
            $sourceBranchId,
        ];
    }
}
