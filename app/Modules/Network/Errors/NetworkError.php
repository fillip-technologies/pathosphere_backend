<?php

namespace App\Modules\Network\Errors;

use App\Modules\Shared\Errors\DomainError;

/** Franchise onboarding and partner account failures with stable codes. */
final class NetworkError
{
    public static function franchiseNotDeletable(): DomainError
    {
        return new DomainError(
            'FRANCHISE_NOT_DELETABLE',
            'Only a franchise still at application, with no branches, documents or agreements, can be deleted. Terminate it instead.',
            409,
        );
    }

    /** @param  list<string>  $missing */
    public static function franchiseNotReady(array $missing): DomainError
    {
        return new DomainError(
            'FRANCHISE_NOT_READY',
            'The franchise goes live only with KYC approved, an active agreement and at least one branch.',
            422,
            array_map(fn (string $requirement) => ['requirement' => $requirement], $missing),
        );
    }

    public static function creditLimitNeedsFinance(): DomainError
    {
        return new DomainError('CREDIT_LIMIT_NEEDS_FINANCE', 'Only head-office finance (ledger adjustment rights) can change a credit limit.', 403, [['field' => 'credit_limit']]);
    }

    public static function documentAlreadyReviewed(): DomainError
    {
        return new DomainError('DOCUMENT_ALREADY_REVIEWED', 'This document has already been verified or rejected. Upload a new copy instead.', 409);
    }

    public static function documentsClosed(): DomainError
    {
        return new DomainError('FRANCHISE_DOCUMENTS_CLOSED', 'A terminated franchise takes no new documents.', 409);
    }

    public static function agreementNotEditable(): DomainError
    {
        return new DomainError('AGREEMENT_NOT_EDITABLE', 'Only a draft agreement can be changed or deleted.', 409);
    }

    public static function franchiseNotApproved(): DomainError
    {
        return new DomainError('FRANCHISE_KYC_INCOMPLETE', 'An agreement goes for signature only after the franchise\'s KYC is approved.', 422);
    }

    public static function partnerPriceListRequired(): DomainError
    {
        return new DomainError(
            'PARTNER_PRICE_LIST_REQUIRED',
            'A wholesale agreement needs the franchise to have a partner price list first.',
            422,
            [['field' => 'billing_model']],
        );
    }

    /** @param  array<string, list<string>>  $pincodesByAgreementNo */
    public static function territoryConflict(array $pincodesByAgreementNo): DomainError
    {
        $details = [];
        foreach ($pincodesByAgreementNo as $agreementNo => $pincodes) {
            $details[] = ['field' => 'territory_pincodes', 'agreement_no' => $agreementNo, 'pincodes' => $pincodes];
        }

        return new DomainError('TERRITORY_CONFLICT', 'Some pincodes are already the exclusive territory of another franchise.', 409, $details);
    }

    public static function invalidWebhookSignature(): DomainError
    {
        return new DomainError('WEBHOOK_SIGNATURE_INVALID', 'The webhook signature is not valid.', 401);
    }
}
