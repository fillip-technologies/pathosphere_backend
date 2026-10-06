<?php

namespace App\Modules\Network\Contracts;

/**
 * E-sign vendor boundary (spec §3, §5.1 step 4). The agreement PDF goes out
 * for signature; the vendor's webhook says when it is signed, and the signed
 * copy is fetched and kept in private storage.
 */
interface ESignProvider
{
    public function requestSignature(ESignRequest $request): string;

    public function hasValidSignature(string $rawBody, string $signatureHeader): bool;

    public function parseWebhook(string $rawBody): ?ESignEvent;

    /** @return string the signed PDF bytes */
    public function downloadSignedDocument(string $reference): string;
}
