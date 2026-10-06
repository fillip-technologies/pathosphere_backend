<?php

namespace App\Modules\Samples\Errors;

use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Errors\ErrorCode;

/** Sample and logistics failures with stable codes. */
final class SampleError
{
    public static function orderNotOpen(): DomainError
    {
        return new DomainError('ORDER_NOT_OPEN_FOR_SAMPLES', 'Samples can be drawn only for a confirmed order that is not finished or cancelled.', 422);
    }

    public static function orderCancelled(): DomainError
    {
        return new DomainError('ORDER_CANCELLED', 'This order was cancelled; its samples cannot be collected.', 422);
    }

    public static function barcodeInUse(): DomainError
    {
        return new DomainError('BARCODE_IN_USE', 'This barcode is already on another sample.', 409, [['field' => 'barcode']]);
    }

    public static function barcodeLocked(): DomainError
    {
        return new DomainError('BARCODE_NOT_CHANGEABLE', 'The barcode can only be changed before the sample is collected.', 422, [['field' => 'barcode']]);
    }

    public static function notForThisBranch(string $action): DomainError
    {
        return new DomainError(ErrorCode::FORBIDDEN, "Only staff of the branch holding it can {$action}.", 403);
    }

    public static function needsManifest(): DomainError
    {
        return new DomainError('SAMPLE_NEEDS_MANIFEST', 'This sample is tested at another lab; send it on a manifest.', 422);
    }

    public static function notReroutable(): DomainError
    {
        return new DomainError('SAMPLE_NOT_REROUTABLE', 'Only a collected or received sample that is not in transit can be re-routed.', 422);
    }

    public static function rerouteNotNeeded(): DomainError
    {
        return new DomainError('REROUTE_NOT_NEEDED', 'The sample already goes to this lab.', 422, [['field' => 'processing_branch_id']]);
    }

    public static function noRoute(): DomainError
    {
        return new DomainError('NO_ROUTE_FOR_TEST', 'No other lab is set up to run these tests from here. Choose the lab.', 422, [['field' => 'processing_branch_id']]);
    }

    public static function routeAmbiguous(): DomainError
    {
        return new DomainError('ROUTE_AMBIGUOUS', "The sample's tests are routed to different labs. Choose the lab.", 422, [['field' => 'processing_branch_id']]);
    }

    public static function labCannotRun(): DomainError
    {
        return new DomainError('LAB_CANNOT_RUN_TEST', 'The chosen lab is not operating or cannot run every test on this sample.', 422, [['field' => 'processing_branch_id']]);
    }

    public static function destinationNotLab(): DomainError
    {
        return new DomainError('DESTINATION_NOT_LAB', 'Manifests go to an operating lab.', 422, [['field' => 'to_branch_id']]);
    }

    public static function openManifestExists(string $manifestId): DomainError
    {
        return new DomainError('MANIFEST_ALREADY_OPEN', 'An open manifest already exists for this route; add the samples to it.', 409, [['manifest_id' => $manifestId]]);
    }

    public static function manifestNotOpen(): DomainError
    {
        return new DomainError('MANIFEST_NOT_OPEN', 'Samples can only be added or removed before the manifest is dispatched.', 409);
    }

    public static function manifestEmpty(): DomainError
    {
        return new DomainError('MANIFEST_EMPTY', 'A manifest needs at least one sample before dispatch.', 422);
    }

    public static function manifestNotInTransit(): DomainError
    {
        return new DomainError('MANIFEST_NOT_IN_TRANSIT', 'Only a dispatched manifest can be received.', 409);
    }

    /** @param  list<array<string, mixed>>  $details  one entry per sample that cannot go on the manifest */
    public static function samplesNotShippable(array $details): DomainError
    {
        return new DomainError(
            (string) $details[0]['code'],
            count($details) === 1 ? (string) $details[0]['message'] : 'Some samples cannot go on this manifest. See details for each.',
            $details[0]['code'] === 'SAMPLE_ON_ANOTHER_MANIFEST' ? 409 : 422,
            $details,
        );
    }

    /** @param  list<string>  $barcodes */
    public static function notOnManifest(array $barcodes): DomainError
    {
        return new DomainError(
            'SAMPLE_NOT_ON_MANIFEST',
            'Some scanned samples are not on this manifest.',
            422,
            array_map(fn (string $barcode) => ['field' => 'items', 'barcode' => $barcode], $barcodes),
        );
    }

    public static function alreadyReceived(string $barcode): DomainError
    {
        return new DomainError('SAMPLE_ALREADY_RECEIVED', 'This sample was already scanned in with a different condition.', 409, [['field' => 'items', 'barcode' => $barcode]]);
    }

    public static function inventoryItemExists(): DomainError
    {
        return new DomainError('INVENTORY_ITEM_EXISTS', 'This branch already has that item and batch. Update its quantity instead.', 409, [['field' => 'item_code'], ['field' => 'batch_no']]);
    }

    public static function inventoryItemInStock(): DomainError
    {
        return new DomainError('INVENTORY_ITEM_IN_STOCK', 'Only an item with no stock left can be removed.', 409);
    }

    public static function stockInsufficient(string $available): DomainError
    {
        return new DomainError('STOCK_INSUFFICIENT', "The sending branch has only {$available} of this batch.", 409, [['field' => 'quantity', 'available' => $available]]);
    }

    public static function senderItemMissing(): DomainError
    {
        return new DomainError('STOCK_ITEM_NOT_AT_SENDER', 'The sending branch has no such item and batch.', 422, [['field' => 'item_code'], ['field' => 'batch_no']]);
    }

    public static function chargeNotAllowed(): DomainError
    {
        return new DomainError(
            'STOCK_CHARGE_NOT_ALLOWED',
            'Only a company branch sending to a franchise branch can charge for stock, and only the sending side sets the charge.',
            422,
            [['field' => 'charge_amount']],
        );
    }

    public static function transferNotEditable(): DomainError
    {
        return new DomainError('STOCK_TRANSFER_NOT_EDITABLE', 'Only a requested transfer can be changed.', 409);
    }
}
