<?php

namespace App\Modules\Network\Services;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Lab\Contracts\PdfRenderer;
use App\Modules\Network\Contracts\ESignEvent;
use App\Modules\Network\Contracts\ESignProvider;
use App\Modules\Network\Contracts\ESignRequest;
use App\Modules\Network\Enums\AgreementStatus;
use App\Modules\Network\Enums\BillingModel;
use App\Modules\Network\Enums\FranchiseStatus;
use App\Modules\Network\Errors\NetworkError;
use App\Modules\Network\Events\AgreementSigned;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\FranchiseAgreement;
use App\Modules\Network\Models\TerritoryPincode;
use App\Modules\Network\StateMachines\AgreementStateMachine;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Context\Actor;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\Files\PrivateFileStore;
use App\Modules\Shared\Files\PrivatePaths;
use App\Modules\Shared\Numbering\FinancialYear;
use App\Modules\Shared\Numbering\NumberSequenceService;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Franchise agreements (spec §5.1 steps 3–4): drafted by HQ, sent to the
 * e-sign vendor, activated by the vendor's webhook. Activation posts the fee
 * and deposit to the ledger (AgreementSigned) and may take the franchise live.
 */
final class AgreementService
{
    private const DEFAULT_NUMBER_FORMAT = 'AGR/{FY}/{seq:4}';

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NetworkDirectory $directory,
        private readonly NumberSequenceService $sequences,
        private readonly AgreementStateMachine $stateMachine,
        private readonly FranchiseService $franchises,
        private readonly AgreementDocumentBuilder $documents,
        private readonly PdfRenderer $pdfRenderer,
        private readonly ESignProvider $eSign,
        private readonly PrivateFileStore $files,
        private readonly CurrentScope $currentScope,
        private readonly CurrentActor $currentActor,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated fields
     * @param  list<string>  $pincodes
     */
    public function create(Franchise $franchise, array $attributes, array $pincodes): FranchiseAgreement
    {
        if ($franchise->status === FranchiseStatus::Terminated) {
            throw NetworkError::agreementNotEditable();
        }

        $this->assertTerritoryIsFree($franchise, $pincodes);

        return DB::transaction(function () use ($franchise, $attributes, $pincodes): FranchiseAgreement {
            $agreement = new FranchiseAgreement($this->withCommissionRule($attributes));
            $agreement->organization_id = $franchise->organization_id;
            $agreement->franchise_id = $franchise->id;
            $agreement->status = AgreementStatus::Draft;
            $agreement->agreement_no = $this->sequences->nextFormatted(
                $franchise->organization_id,
                'agreement',
                (string) ($this->directory->organizationSettings($franchise->organization_id)['agreement_number_format'] ?? self::DEFAULT_NUMBER_FORMAT),
                [],
                FinancialYear::containing(CarbonImmutable::now()),
            );
            $agreement->save();
            $this->replacePincodes($agreement, $pincodes);
            $this->auditLogger->recordCreated('franchise_agreement.create', $agreement);

            return $agreement->load('pincodes');
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  list<string>|null  $pincodes  null leaves the territory as it is
     */
    public function update(FranchiseAgreement $agreement, array $changes, ?array $pincodes): FranchiseAgreement
    {
        $this->assertDraft($agreement);

        if ($pincodes !== null) {
            $this->assertTerritoryIsFree($agreement->franchise, $pincodes);
        }

        $changes = $this->withCommissionRule($changes + ['billing_model' => $agreement->billing_model->value]);
        $commission = array_key_exists('commission_pct', $changes) ? $changes['commission_pct'] : $agreement->commission_pct;

        if ($changes['billing_model'] === BillingModel::RevenueShare->value && $commission === null) {
            throw ValidationException::withMessages(['commission_pct' => 'A revenue-share agreement needs a commission percentage.']);
        }

        return DB::transaction(function () use ($agreement, $changes, $pincodes): FranchiseAgreement {
            $agreement->fill($changes)->save();
            $this->auditLogger->recordChanges('franchise_agreement.update', $agreement);

            if ($pincodes !== null) {
                $old = $agreement->pincodeList();
                $this->replacePincodes($agreement, $pincodes);
                $this->auditLogger->record('franchise_agreement.territory', $agreement, ['pincodes' => $old], ['pincodes' => $pincodes]);
            }

            return $agreement->load('pincodes');
        });
    }

    public function delete(FranchiseAgreement $agreement): void
    {
        $this->assertDraft($agreement);

        DB::transaction(function () use ($agreement): void {
            $agreement->delete();
            $this->auditLogger->record('franchise_agreement.delete', $agreement);
        });
    }

    /**
     * Step 4: the agreement goes out for signature. The franchise's KYC must
     * be approved (or it is already trading and renewing), and the territory
     * must still be free.
     */
    public function sendForSign(StaffContext $staff, FranchiseAgreement $agreement): FranchiseAgreement
    {
        $this->assertDraft($agreement);
        $franchise = $agreement->franchise;

        if (! in_array($franchise->status, [FranchiseStatus::Approved, FranchiseStatus::Active, FranchiseStatus::Suspended], true)) {
            throw NetworkError::franchiseNotApproved();
        }

        if ($agreement->billing_model === BillingModel::Wholesale && $franchise->partner_price_list_id === null) {
            throw NetworkError::partnerPriceListRequired();
        }

        $this->assertTerritoryIsFree($franchise, $agreement->pincodeList());

        $pdf = $this->pdfRenderer->render($this->documents->html($agreement));
        $reference = $this->eSign->requestSignature(new ESignRequest(
            $agreement->id,
            $agreement->agreement_no,
            $franchise->owner_name,
            $franchise->email,
            $franchise->phone,
            $pdf,
        ));

        return DB::transaction(function () use ($staff, $agreement, $pdf, $reference): FranchiseAgreement {
            $unsigned = PrivatePaths::agreement($agreement->franchise_id, $agreement->id, signed: false);
            if (! $this->files->exists($unsigned)) {
                $this->files->putNew($unsigned, $pdf);
            }

            $this->stateMachine->transition($agreement, AgreementStatus::SentForSign, [
                'esign_reference' => $reference,
                'approved_by' => $staff->user()->id,
            ]);

            return $agreement;
        });
    }

    /**
     * The e-sign vendor's verdict. Idempotent: only an agreement still out
     * for signature changes; repeats of the same webhook do nothing.
     *
     * @return bool whether anything changed
     */
    public function applySignatureEvent(ESignEvent $event): bool
    {
        $agreement = $this->currentScope->runAs(
            ScopeContext::system(),
            fn () => FranchiseAgreement::query()->where('esign_reference', $event->reference)->first(),
        );

        if ($agreement === null || $agreement->status !== AgreementStatus::SentForSign) {
            return false;
        }

        // Webhooks have no signed-in user: act as the system of the agreement's organization.
        return $this->currentActor->runAs(
            Actor::system($agreement->organization_id),
            fn () => $this->currentScope->runAs(ScopeContext::system($agreement->organization_id), fn () => $event->outcome === ESignEvent::SIGNED
                ? $this->activate($agreement, $event->occurredAt)
                : $this->returnToDraft($agreement)),
        );
    }

    /** Super Admin ends an agreement early (spec §5.1). */
    public function terminate(FranchiseAgreement $agreement): FranchiseAgreement
    {
        $this->stateMachine->transition($agreement, AgreementStatus::Terminated);

        return $agreement;
    }

    /** Daily: active agreements past their end date expire (spec §5.1, System). */
    public function expireEnded(CarbonImmutable $today): int
    {
        $ended = FranchiseAgreement::query()
            ->where('status', AgreementStatus::Active)
            ->where('end_date', '<', $today->toDateString())
            ->get();

        foreach ($ended as $agreement) {
            $this->stateMachine->transition($agreement, AgreementStatus::Expired);
        }

        return $ended->count();
    }

    public function downloadSigned(FranchiseAgreement $agreement): ?StreamedResponse
    {
        if ($agreement->signed_doc_path === null) {
            return null;
        }

        $this->auditLogger->record('franchise_agreement.document_viewed', $agreement);

        return $this->files->download($agreement->signed_doc_path, "agreement-{$agreement->id}.pdf");
    }

    private function activate(FranchiseAgreement $agreement, CarbonImmutable $signedAt): bool
    {
        $signedPath = PrivatePaths::agreement($agreement->franchise_id, $agreement->id, signed: true);
        $signedPdf = $this->files->exists($signedPath) ? null : $this->eSign->downloadSignedDocument((string) $agreement->esign_reference);

        DB::transaction(function () use ($agreement, $signedAt, $signedPath, $signedPdf): void {
            $agreement = FranchiseAgreement::query()->lockForUpdate()->findOrFail($agreement->id);

            if ($agreement->status !== AgreementStatus::SentForSign) {
                return;
            }

            if ($signedPdf !== null) {
                $this->files->putNew($signedPath, $signedPdf);
            }

            // A renewal replaces the agreement in force (one active per franchise).
            $previous = FranchiseAgreement::query()
                ->where('franchise_id', $agreement->franchise_id)
                ->where('status', AgreementStatus::Active)
                ->first();
            if ($previous !== null) {
                $this->stateMachine->transition($previous, AgreementStatus::Expired);
            }

            $this->stateMachine->transition($agreement, AgreementStatus::Active, [
                'signed_at' => $signedAt,
                'signed_doc_path' => $signedPath,
            ]);

            event(new AgreementSigned(
                $agreement->id,
                $agreement->agreement_no,
                $agreement->franchise_id,
                $agreement->organization_id,
                $agreement->franchise_fee,
                $agreement->security_deposit,
            ));

            $this->franchises->activateIfReady($agreement->franchise_id);
        });

        return true;
    }

    private function returnToDraft(FranchiseAgreement $agreement): bool
    {
        $this->stateMachine->transition($agreement, AgreementStatus::Draft, ['esign_reference' => null]);

        return true;
    }

    private function assertDraft(FranchiseAgreement $agreement): void
    {
        if ($agreement->status !== AgreementStatus::Draft) {
            throw NetworkError::agreementNotEditable();
        }
    }

    /**
     * Pincodes already exclusive to another franchise, in agreements out for
     * signature or in force. Checked across the whole organization: a
     * region-level manager must not grant a pincode held elsewhere.
     *
     * @param  list<string>  $pincodes
     */
    private function assertTerritoryIsFree(Franchise $franchise, array $pincodes): void
    {
        if ($pincodes === []) {
            return;
        }

        $conflicts = $this->currentScope->runAs(ScopeContext::system($franchise->organization_id), function () use ($franchise, $pincodes): array {
            $holders = FranchiseAgreement::query()
                ->where('franchise_id', '!=', $franchise->id)
                ->whereIn('status', [AgreementStatus::SentForSign, AgreementStatus::Active])
                ->pluck('agreement_no', 'id');

            $byAgreement = [];
            TerritoryPincode::query()
                ->whereIn('agreement_id', $holders->keys()->all())
                ->whereIn('pincode', $pincodes)
                ->orderBy('pincode')
                ->get()
                ->each(function (TerritoryPincode $row) use ($holders, &$byAgreement): void {
                    $byAgreement[(string) $holders[$row->agreement_id]][] = $row->pincode;
                });

            return $byAgreement;
        });

        if ($conflicts !== []) {
            throw NetworkError::territoryConflict($conflicts);
        }
    }

    /** @param  list<string>  $pincodes */
    private function replacePincodes(FranchiseAgreement $agreement, array $pincodes): void
    {
        TerritoryPincode::query()->where('agreement_id', $agreement->id)->delete();

        foreach (array_values(array_unique($pincodes)) as $pincode) {
            $row = new TerritoryPincode(['pincode' => $pincode]);
            $row->agreement_id = $agreement->id;
            $row->save();
        }
    }

    /**
     * Commission belongs to the revenue-share model only (spec §7.1).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withCommissionRule(array $attributes): array
    {
        if (($attributes['billing_model'] ?? null) === BillingModel::Wholesale->value) {
            $attributes['commission_pct'] = null;
        }

        return $attributes;
    }
}
