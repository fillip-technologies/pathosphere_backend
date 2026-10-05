<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Architecture\ArchitectureRule;
use Tests\Support\Architecture\Rules;
use Tests\Support\Architecture\SourceFile;

/**
 * Enforces the code rules MySQL cannot (spec §4.2, BUILD_PLAN Phase 0.7).
 * Each rule is also run against a deliberately bad sample, proving it would
 * catch a real violation.
 */
final class ArchitectureTest extends TestCase
{
    /** @return iterable<string, array{ArchitectureRule}> */
    public static function rules(): iterable
    {
        foreach (Rules::all() as $rule) {
            yield (new \ReflectionClass($rule))->getShortName() => [$rule];
        }
    }

    #[DataProvider('rules')]
    public function test_application_code_follows_the_rule(ArchitectureRule $rule): void
    {
        $violations = $rule->violations(SourceFile::allUnder(dirname(__DIR__, 2).'/app'));

        $this->assertSame([], $violations, $rule->description()."\n".implode("\n", $violations));
    }

    #[DataProvider('rules')]
    public function test_rule_catches_its_bad_sample(ArchitectureRule $rule): void
    {
        $sampleDirectory = dirname(__DIR__).'/Fixtures/Architecture/'.(new \ReflectionClass($rule))->getShortName();

        $violations = $rule->violations(SourceFile::allUnder($sampleDirectory, 'php.txt'));

        $this->assertNotEmpty($violations, "The rule did not catch its bad sample in {$sampleDirectory}.");
    }
}
