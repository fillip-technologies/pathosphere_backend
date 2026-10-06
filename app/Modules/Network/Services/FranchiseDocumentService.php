<?php

namespace App\Modules\Network\Services;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Network\Contracts\KycCheckRequest;
use App\Modules\Network\Contracts\KycVerifier;
use App\Modules\Network\Enums\FranchiseDocumentStatus;
use App\Modules\Network\Enums\FranchiseDocumentType;
use App\Modules\Network\Enums\FranchiseStatus;
use App\Modules\Network\Errors\NetworkError;
use App\Modules\Network\Jobs\RunKycCheck;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\FranchiseDocument;
use App\Modules\Network\StateMachines\FranchiseDocumentStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Files\PrivateFileStore;
use App\Modules\Shared\Files\PrivatePaths;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * KYC papers (spec §5.1 step 2): uploaded to private storage, checked by the
 * KYC vendor where it can, then verified or rejected by a person. When every
 * mandatory paper is verified, the franchise's KYC is approved.
 */
final class FranchiseDocumentService
{
    public function __construct(
        private readonly PrivateFileStore $files,
        private readonly FranchiseService $franchises,
        private readonly FranchiseDocumentStateMachine $stateMachine,
        private readonly KycVerifier $kycVerifier,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function upload(Franchise $franchise, FranchiseDocumentType $type, UploadedFile $file, ?string $expiresOn): FranchiseDocument
    {
        if ($franchise->status === FranchiseStatus::Terminated) {
            throw NetworkError::documentsClosed();
        }

        return DB::transaction(function () use ($franchise, $type, $file, $expiresOn): FranchiseDocument {
            $document = new FranchiseDocument(['doc_type' => $type, 'expires_on' => $expiresOn]);
            $document->id = $document->newUniqueId();
            $document->organization_id = $franchise->organization_id;
            $document->franchise_id = $franchise->id;
            $document->status = FranchiseDocumentStatus::Uploaded;
            $document->file_path = $this->files->putNew(
                PrivatePaths::kycDocument($franchise->id, $document->id, $file->getClientOriginalExtension() ?: (string) $file->extension()),
                (string) $file->get(),
            )->path;
            $document->save();
            $this->auditLogger->recordCreated('franchise_document.upload', $document);

            $this->franchises->startKyc($franchise);

            // The vendor call is slow and may fail; it runs after commit and retries on its own.
            RunKycCheck::dispatch($document->id, $document->organization_id)->afterCommit();

            return $document;
        });
    }

    /** The KYC vendor's verdict. Only a passed check changes the status; a person still reviews every paper. */
    public function applyVendorCheck(string $documentId): void
    {
        DB::transaction(function () use ($documentId): void {
            $document = FranchiseDocument::query()->lockForUpdate()->find($documentId);

            if ($document === null || $document->status !== FranchiseDocumentStatus::Uploaded || $document->vendor_reference !== null) {
                return;
            }

            $franchise = Franchise::query()->findOrFail($document->franchise_id);
            $result = $this->kycVerifier->verify(new KycCheckRequest(
                $document->doc_type,
                $franchise->legal_name,
                $franchise->pan,
                $franchise->gstin,
                $franchise->bank_account_no,
                $franchise->bank_ifsc,
            ));

            if ($result->passed()) {
                $this->stateMachine->transition($document, FranchiseDocumentStatus::AutoVerified, ['vendor_reference' => $result->reference]);

                return;
            }

            if ($result->reference !== null) {
                $document->forceFill(['vendor_reference' => $result->reference])->save();
                $this->auditLogger->recordChanges('franchise_document.vendor_check_failed', $document);
            }
        });
    }

    public function review(StaffContext $staff, FranchiseDocument $document, FranchiseDocumentStatus $decision, ?string $rejectionNote): FranchiseDocument
    {
        if (! $document->status->awaitsReview()) {
            throw NetworkError::documentAlreadyReviewed();
        }

        return DB::transaction(function () use ($staff, $document, $decision, $rejectionNote): FranchiseDocument {
            $this->stateMachine->transition($document, $decision, [
                'verified_by' => $staff->user()->id,
                'verified_at' => CarbonImmutable::now(),
                'rejection_note' => $decision === FranchiseDocumentStatus::Rejected ? $rejectionNote : null,
            ]);

            $franchise = Franchise::query()->lockForUpdate()->findOrFail($document->franchise_id);
            if ($decision === FranchiseDocumentStatus::Verified && $this->missingMandatoryDocuments($franchise) === []) {
                $this->franchises->approveKyc($franchise);
            }

            return $document;
        });
    }

    /**
     * Mandatory papers with no verified copy yet. The GST certificate is
     * mandatory only for a franchise registered for GST.
     *
     * @return list<FranchiseDocumentType>
     */
    public function missingMandatoryDocuments(Franchise $franchise): array
    {
        $required = array_map(fn (string $type) => FranchiseDocumentType::from($type), (array) config('pathology.franchise.mandatory_documents'));

        if ($franchise->gstin !== null) {
            $required[] = FranchiseDocumentType::GstCertificate;
        }

        $verified = FranchiseDocument::query()
            ->where('franchise_id', $franchise->id)
            ->where('status', FranchiseDocumentStatus::Verified)
            ->pluck('doc_type')
            ->all();

        return array_values(array_filter($required, fn (FranchiseDocumentType $type) => ! in_array($type, $verified, true)));
    }

    /** Identity papers are personal data: every download is audited (spec §10). */
    public function download(FranchiseDocument $document): StreamedResponse
    {
        $this->auditLogger->record('franchise_document.viewed', $document);
        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);

        return $this->files->download($document->file_path, "{$document->doc_type->value}-{$document->id}.{$extension}");
    }
}
