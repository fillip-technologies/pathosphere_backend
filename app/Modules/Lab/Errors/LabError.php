<?php

namespace App\Modules\Lab\Errors;

use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Errors\ErrorCode;

/** Results, signing and report failures with stable codes. */
final class LabError
{
    public static function notForThisLab(string $action): DomainError
    {
        return new DomainError(ErrorCode::FORBIDDEN, "Only staff of the lab running this test can {$action}.", 403);
    }

    public static function testNotOpen(): DomainError
    {
        return new DomainError('TEST_NOT_OPEN_FOR_RESULTS', 'This test is not waiting for results: it was withdrawn or is already verified. Ask for a rerun to change verified results.', 409);
    }

    /** @param  list<array<string, mixed>>  $details  one entry per bad value */
    public static function invalidResults(array $details): DomainError
    {
        return new DomainError(
            (string) $details[0]['code'],
            count($details) === 1 ? (string) $details[0]['message'] : 'Some results cannot be saved. See details for each.',
            422,
            $details,
        );
    }

    public static function nothingToVerify(): DomainError
    {
        return new DomainError('RESULTS_INCOMPLETE', 'Every parameter needs a result before the test can be verified.', 422);
    }

    public static function resultNotCurrent(): DomainError
    {
        return new DomainError('RESULT_NOT_CURRENT', 'This result belongs to an earlier run and cannot be verified.', 409);
    }

    public static function reportReleased(): DomainError
    {
        return new DomainError('REPORT_ALREADY_RELEASED', 'The report with this test is released. Amend the report before rerunning the test.', 409);
    }

    public static function rerunNotNeeded(): DomainError
    {
        return new DomainError('RERUN_NOT_NEEDED', 'This test has no results yet; enter them instead.', 422);
    }

    public static function signatoryNotAuthorised(): DomainError
    {
        return new DomainError('SIGNATORY_NOT_AUTHORISED', 'You are not an active signatory for this department at this lab, or your registration has expired.', 403);
    }

    public static function departmentNotOnReport(): DomainError
    {
        return new DomainError('DEPARTMENT_NOT_ON_REPORT', 'This report has no tests from that department.', 422, [['field' => 'department_ids']]);
    }

    public static function departmentNotVerified(string $departmentName): DomainError
    {
        return new DomainError('RESULTS_NOT_VERIFIED', "Every {$departmentName} result must be verified before it can be signed.", 422, [['field' => 'department_ids']]);
    }

    public static function nothingToSign(): DomainError
    {
        return new DomainError('NOTHING_TO_SIGN', 'There is nothing on this report that you can sign now.', 422);
    }

    public static function reportNotSignable(): DomainError
    {
        return new DomainError('REPORT_NOT_SIGNABLE', 'Only a report that is not yet released can be signed.', 409);
    }

    public static function reportNotReleasable(): DomainError
    {
        return new DomainError('REPORT_NOT_SIGNED', 'Every department must be signed before the report is released.', 409);
    }

    public static function reportWithheld(): DomainError
    {
        return new DomainError('REPORT_WITHHELD_FOR_DUES', 'The client has overdue invoices; the report stays withheld until they are paid.', 409);
    }

    public static function reportNotAmendable(): DomainError
    {
        return new DomainError('REPORT_NOT_AMENDABLE', 'Only the released, current version of a report can be amended.', 409);
    }

    public static function pdfNotReady(): DomainError
    {
        return new DomainError('REPORT_PDF_NOT_READY', 'The report PDF is still being prepared. Try again in a minute.', 409);
    }

    public static function signatoryUserNotEligible(): DomainError
    {
        return new DomainError('SIGNATORY_USER_NOT_ELIGIBLE', 'The user must be active staff whose role can sign reports.', 422, [['field' => 'user_id']]);
    }

    public static function disciplineMismatch(): DomainError
    {
        return new DomainError('SIGNATORY_DISCIPLINE_MISMATCH', "The signatory's discipline does not cover this department.", 422, [['field' => 'signing_discipline']]);
    }

    public static function signatoryExists(): DomainError
    {
        return new DomainError('SIGNATORY_ALREADY_EXISTS', 'This doctor is already a signatory for this department at this lab.', 409);
    }

    public static function agentKeyInvalid(): DomainError
    {
        return new DomainError(ErrorCode::UNAUTHENTICATED, 'The interface agent key is missing, wrong or revoked.', 401);
    }

    public static function agentAddressNotAllowed(): DomainError
    {
        return new DomainError(ErrorCode::FORBIDDEN, 'Requests from this address are not allowed for this interface agent.', 403);
    }
}
