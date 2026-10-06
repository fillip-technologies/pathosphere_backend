<?php

namespace App\Modules\Locker\Http\Resources;

use App\Modules\Locker\Services\DigiLockerListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DigiLockerListing */
final class DigiLockerDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uri' => $this->document->uri,
            'name' => $this->document->name,
            'doc_type' => $this->document->docType,
            'issuer' => $this->document->issuer,
            'issued_on' => $this->document->issuedOn?->toDateString(),
            // Only PDFs and images can go into the locker.
            'importable' => $this->importable,
            'imported_record_id' => $this->importedRecordId,
        ];
    }
}
