<?php

namespace Tests\Unit\Catalogue;

use App\Modules\Catalogue\Domain\ProcessingBranchResolver;
use App\Modules\Catalogue\Domain\RoutingRuleEntry;
use PHPUnit\Framework\TestCase;

/** The routing algorithm of spec §7.4. */
final class ProcessingBranchResolverTest extends TestCase
{
    private const PSC = 'psc';

    private const CLINICAL_LAB = 'clinical-lab';

    private const REFERENCE_LAB = 'reference-lab';

    public function test_a_test_specific_rule_beats_the_default_rule(): void
    {
        $rules = [
            new RoutingRuleEntry(null, self::CLINICAL_LAB, 1),
            new RoutingRuleEntry('vitamin-d', self::REFERENCE_LAB, 1),
        ];

        $this->assertSame(self::REFERENCE_LAB, $this->resolve('vitamin-d', $rules, $this->bothLabsRun('vitamin-d')));
    }

    public function test_lower_priority_number_wins_and_the_next_is_a_fallback(): void
    {
        $rules = [
            new RoutingRuleEntry(null, self::REFERENCE_LAB, 2),
            new RoutingRuleEntry(null, self::CLINICAL_LAB, 1),
        ];

        $this->assertSame(self::CLINICAL_LAB, $this->resolve('cbc', $rules, $this->bothLabsRun('cbc')));

        // Clinical lab's analyser is down: its capability is off.
        $this->assertSame(self::REFERENCE_LAB, $this->resolve('cbc', $rules, [self::REFERENCE_LAB => ['cbc' => true]]));
    }

    public function test_a_lab_with_no_rules_runs_its_own_tests(): void
    {
        $capable = [self::CLINICAL_LAB => ['cbc' => true]];

        $this->assertSame(self::CLINICAL_LAB, ProcessingBranchResolver::resolve(self::CLINICAL_LAB, 'cbc', [], $capable));
    }

    public function test_no_capable_lab_means_no_route(): void
    {
        $rules = [new RoutingRuleEntry(null, self::CLINICAL_LAB, 1)];

        $this->assertNull($this->resolve('karyotype', $rules, $this->bothLabsRun('cbc')));
    }

    public function test_a_rule_for_another_test_is_ignored(): void
    {
        $rules = [new RoutingRuleEntry('hba1c', self::REFERENCE_LAB, 1)];

        $this->assertNull($this->resolve('cbc', $rules, $this->bothLabsRun('cbc', 'hba1c') + [self::CLINICAL_LAB => []]));
    }

    /**
     * @param  list<RoutingRuleEntry>  $rules
     * @param  array<string, array<string, true>>  $capable
     */
    private function resolve(string $testId, array $rules, array $capable): ?string
    {
        return ProcessingBranchResolver::resolve(self::PSC, $testId, $rules, $capable);
    }

    /** @return array<string, array<string, true>> */
    private function bothLabsRun(string ...$testIds): array
    {
        $tests = array_fill_keys($testIds, true);

        return [self::CLINICAL_LAB => $tests, self::REFERENCE_LAB => $tests];
    }
}
