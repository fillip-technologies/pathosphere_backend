<?php

namespace Tests\Unit\Lab;

use App\Modules\Catalogue\Domain\ReferenceRangeEntry;
use App\Modules\Catalogue\Domain\ReferenceRangeSelector;
use App\Modules\Catalogue\Enums\ResultType;
use App\Modules\Catalogue\Enums\SigningDiscipline;
use App\Modules\Lab\Domain\FormulaEvaluator;
use App\Modules\Lab\Domain\ReportReadiness;
use App\Modules\Lab\Domain\ResultFlagger;
use App\Modules\Lab\Domain\ResultValueParser;
use App\Modules\Lab\Domain\SignatoryCredential;
use App\Modules\Lab\Domain\SignatoryEligibility;
use App\Modules\Lab\Enums\ReportStatus;
use App\Modules\Lab\Enums\ResultFlag;
use App\Modules\Lab\Enums\WorklistStatus;
use App\Modules\Lab\StateMachines\ReportStateMachine;
use App\Modules\Lab\StateMachines\WorklistEntryStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\Enums\Gender;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LabRulesTest extends TestCase
{
    public function test_the_patients_gender_and_narrowest_age_band_pick_the_range(): void
    {
        $adults = $this->range(null, 18 * 365, null, '12', '17');
        $women = $this->range(Gender::Female, 18 * 365, null, '12', '15');
        $children = $this->range(null, 0, 18 * 365 - 1, '11', '14');

        $this->assertSame($women, ReferenceRangeSelector::select([$adults, $women, $children], Gender::Female, 30 * 365));
        $this->assertSame($adults, ReferenceRangeSelector::select([$adults, $women, $children], Gender::Male, 30 * 365));
        $this->assertSame($children, ReferenceRangeSelector::select([$adults, $women, $children], Gender::Female, 5 * 365));
        $this->assertNull(ReferenceRangeSelector::select([$women], Gender::Male, 30 * 365));
        // Age unknown: age bands are ignored, gender still wins.
        $this->assertSame($women, ReferenceRangeSelector::select([$children, $women], Gender::Female, null));
    }

    /** @return array<string, array{string, ResultFlag}> */
    public static function haemoglobinValues(): array
    {
        return [
            'critically low' => ['6.9', ResultFlag::CriticalLow],
            'on the critical limit is only low' => ['7', ResultFlag::Low],
            'low' => ['11.9', ResultFlag::Low],
            'on the lower limit' => ['12', ResultFlag::Normal],
            'normal' => ['14.2', ResultFlag::Normal],
            'on the upper limit' => ['17.0000', ResultFlag::Normal],
            'high' => ['17.1', ResultFlag::High],
            'critically high' => ['20.5', ResultFlag::CriticalHigh],
        ];
    }

    #[DataProvider('haemoglobinValues')]
    public function test_results_are_flagged_against_normal_and_critical_limits(string $value, ResultFlag $expected): void
    {
        $range = new ReferenceRangeEntry(null, null, null, '12', '17', '7', '20', null);

        $this->assertSame($expected, ResultFlagger::flag($value, $range));
    }

    public function test_results_without_a_number_or_a_range_get_no_flag(): void
    {
        $this->assertNull(ResultFlagger::flag(null, $this->range(null, null, null, '1', '2')));
        $this->assertNull(ResultFlagger::flag('5', null));
        $this->assertSame(ResultFlag::Normal, ResultFlagger::flag('500', $this->range(null, null, null, null, null)));
    }

    public function test_critical_flags_are_marked_critical_and_printed(): void
    {
        $this->assertTrue(ResultFlag::CriticalHigh->isCritical());
        $this->assertFalse(ResultFlag::High->isCritical());
        $this->assertSame('L', ResultFlag::Low->printedMark());
        $this->assertSame('', ResultFlag::Normal->printedMark());
    }

    public function test_numbers_keep_their_analyser_qualifier_and_parse_the_value(): void
    {
        $plain = ResultValueParser::parse(ResultType::Numeric, ' 13.45 ', null);
        $qualified = ResultValueParser::parse(ResultType::Numeric, '<0.5', null);

        $this->assertSame(['13.45', '13.45'], [$plain->value, $plain->numeric]);
        $this->assertSame(['<0.5', '0.5'], [$qualified->value, $qualified->numeric]);
        $this->assertSame('-2', ResultValueParser::parse(ResultType::Numeric, '-2', null)->numeric);
    }

    /** @return array<string, array{ResultType, string, list<string>|null}> */
    public static function invalidValues(): array
    {
        return [
            'text in a number' => [ResultType::Numeric, 'high', null],
            'too many decimals' => [ResultType::Numeric, '1.23456', null],
            'two numbers' => [ResultType::Numeric, '1 2', null],
            'empty' => [ResultType::Text, '  ', null],
            'option not offered' => [ResultType::Option, 'Maybe', ['Reactive', 'Non-reactive']],
        ];
    }

    /** @param  list<string>|null  $options */
    #[DataProvider('invalidValues')]
    public function test_values_that_do_not_fit_their_parameter_are_refused(ResultType $type, string $value, ?array $options): void
    {
        $this->expectException(InvalidArgumentException::class);

        ResultValueParser::parse($type, $value, $options);
    }

    public function test_options_are_stored_with_their_catalogue_spelling(): void
    {
        $this->assertSame('Non-reactive', ResultValueParser::parse(ResultType::Option, 'non-REACTIVE', ['Reactive', 'Non-reactive'])->value);
        $this->assertNull(ResultValueParser::parse(ResultType::Option, 'Reactive', ['Reactive', 'Non-reactive'])->numeric);
    }

    public function test_calculated_values_round_to_the_parameters_decimals(): void
    {
        $value = ResultValueParser::formatCalculated(BigDecimal::of('118.66666'), 1);

        $this->assertSame(['118.7', '118.7'], [$value->value, $value->numeric]);
    }

    public function test_the_ldl_formula_is_worked_out_exactly(): void
    {
        $ldl = FormulaEvaluator::evaluate('TC - HDL - (TG / 5)', ['TC' => '210', 'HDL' => '45', 'TG' => '183']);

        $this->assertSame('128.4', (string) $ldl?->strippedOfTrailingZeros());
        $this->assertSame(['TC', 'HDL', 'TG'], FormulaEvaluator::inputs('TC - HDL - (TG / 5)'));
    }

    public function test_formulas_respect_precedence_parentheses_and_unary_minus(): void
    {
        $this->assertSame('14', (string) FormulaEvaluator::evaluate('2 + 3 * 4', []));
        $this->assertSame('20', (string) FormulaEvaluator::evaluate('(2 + 3) * 4', []));
        $this->assertSame('-1', (string) FormulaEvaluator::evaluate('-a + 2', ['A' => '3']));
        $this->assertSame('0.5', (string) FormulaEvaluator::evaluate('x / 4', ['x' => '2'])?->strippedOfTrailingZeros());
    }

    public function test_a_formula_waits_for_missing_inputs_and_never_divides_by_zero(): void
    {
        $this->assertNull(FormulaEvaluator::evaluate('TC - HDL', ['TC' => '200', 'HDL' => null]));
        $this->assertNull(FormulaEvaluator::evaluate('TC / HDL', ['TC' => '200', 'HDL' => '0']));
    }

    /** @return array<string, array{string}> */
    public static function badFormulas(): array
    {
        return [
            'unknown parameter' => ['TC - LDL2'],
            'function call' => ["system('ls')"],
            'php variable' => ['$x + 1'],
            'unbalanced' => ['(TC - 1'],
            'dangling operator' => ['TC -'],
            'two values in a row' => ['TC 5'],
        ];
    }

    #[DataProvider('badFormulas')]
    public function test_malformed_formulas_are_refused_without_running_anything(string $formula): void
    {
        $this->expectException(InvalidArgumentException::class);

        FormulaEvaluator::evaluate($formula, ['TC' => '200']);
    }

    public function test_a_signatory_signs_only_their_department_at_their_lab_while_valid(): void
    {
        $today = CarbonImmutable::parse('2026-10-10');
        $haematology = $this->credential('lab-1', 'dept-haem', SigningDiscipline::Pathology, '2026-10-10');
        $biochemist = $this->credential('lab-1', 'dept-bio', SigningDiscipline::Biochemistry, null);

        $sign = fn (array $credentials, string $lab, string $department, SigningDiscipline $discipline, ?CarbonImmutable $on = null) => SignatoryEligibility::credentialFor($credentials, 'user-1', $lab, $department, $discipline, $on ?? $today);

        $this->assertSame($haematology, $sign([$haematology, $biochemist], 'lab-1', 'dept-haem', SigningDiscipline::Pathology));
        $this->assertSame($biochemist, $sign([$haematology, $biochemist], 'lab-1', 'dept-bio', SigningDiscipline::Biochemistry));
        $this->assertNull($sign([$haematology], 'lab-2', 'dept-haem', SigningDiscipline::Pathology), 'another lab');
        $this->assertNull($sign([$haematology], 'lab-1', 'dept-bio', SigningDiscipline::Biochemistry), 'another department');
        $this->assertNull($sign([$haematology], 'lab-1', 'dept-haem', SigningDiscipline::Pathology, $today->addDay()), 'registration expired');
        $this->assertNull($sign([$this->credential('lab-1', 'dept-haem', SigningDiscipline::Pathology, null, active: false)], 'lab-1', 'dept-haem', SigningDiscipline::Pathology), 'disabled');
        // A biochemist's row on a pathology department does not count (spec §10).
        $this->assertNull($sign([$this->credential('lab-1', 'dept-haem', SigningDiscipline::Biochemistry, null)], 'lab-1', 'dept-haem', SigningDiscipline::Pathology));
    }

    public function test_pathologists_cover_every_discipline_and_others_only_their_own(): void
    {
        $this->assertTrue(SignatoryEligibility::disciplineCovers(SigningDiscipline::Pathology, SigningDiscipline::Microbiology));
        $this->assertTrue(SignatoryEligibility::disciplineCovers(SigningDiscipline::Microbiology, SigningDiscipline::Microbiology));
        $this->assertFalse(SignatoryEligibility::disciplineCovers(SigningDiscipline::Biochemistry, SigningDiscipline::Pathology));
        $this->assertFalse(SignatoryEligibility::disciplineCovers(SigningDiscipline::Microbiology, SigningDiscipline::Biochemistry));
    }

    public function test_a_report_waits_for_verification_then_for_every_department_to_sign(): void
    {
        $verified = WorklistStatus::Verified;

        $this->assertSame(ReportStatus::Draft, ReportReadiness::statusFor([], []));
        $this->assertSame(ReportStatus::Draft, ReportReadiness::statusFor(['haem' => [$verified], 'bio' => [$verified, WorklistStatus::Entered]], ['haem']));
        $this->assertSame(ReportStatus::PendingSignature, ReportReadiness::statusFor(['haem' => [$verified], 'bio' => [$verified]], ['haem']));
        $this->assertSame(ReportStatus::Signed, ReportReadiness::statusFor(['haem' => [$verified], 'bio' => [$verified]], ['bio', 'haem']));

        $this->assertTrue(ReportReadiness::departmentIsVerified(['haem' => [$verified], 'bio' => [WorklistStatus::Pending]], 'haem'));
        $this->assertFalse(ReportReadiness::departmentIsVerified(['haem' => [$verified], 'bio' => [WorklistStatus::Pending]], 'bio'));
        $this->assertFalse(ReportReadiness::departmentIsVerified(['haem' => [$verified]], 'micro'));
    }

    public function test_report_and_worklist_transitions_follow_the_lifecycle(): void
    {
        $reports = new ReportStateMachine(new AuditLogger(new CurrentActor));
        $tests = new WorklistEntryStateMachine(new AuditLogger(new CurrentActor));

        $this->assertTrue($reports->canTransition(ReportStatus::Signed, ReportStatus::Released));
        $this->assertTrue($reports->canTransition(ReportStatus::Signed, ReportStatus::Withheld));
        $this->assertTrue($reports->canTransition(ReportStatus::Withheld, ReportStatus::Released));
        $this->assertTrue($reports->canTransition(ReportStatus::Released, ReportStatus::Amended));
        $this->assertFalse($reports->canTransition(ReportStatus::PendingSignature, ReportStatus::Released), 'release needs every signature');
        $this->assertFalse($reports->canTransition(ReportStatus::Released, ReportStatus::Draft), 'released content only changes by a new version');
        $this->assertFalse($reports->canTransition(ReportStatus::Amended, ReportStatus::Released));

        $this->assertTrue($tests->canTransition(WorklistStatus::Verified, WorklistStatus::Pending), 'rerun');
        $this->assertFalse($tests->canTransition(WorklistStatus::Verified, WorklistStatus::Withdrawn));
        $this->assertFalse($tests->canTransition(WorklistStatus::Withdrawn, WorklistStatus::Pending));
    }

    private function range(?Gender $gender, ?int $minDays, ?int $maxDays, ?string $low, ?string $high): ReferenceRangeEntry
    {
        return new ReferenceRangeEntry($gender, $minDays, $maxDays, $low, $high, null, null, null);
    }

    private function credential(string $lab, string $department, SigningDiscipline $discipline, ?string $validTill, bool $active = true): SignatoryCredential
    {
        return new SignatoryCredential('sig-'.$department, 'user-1', $lab, $department, $discipline, $validTill === null ? null : CarbonImmutable::parse($validTill), $active);
    }
}
