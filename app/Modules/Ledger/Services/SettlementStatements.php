<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Lab\Contracts\PdfRenderer;
use App\Modules\Ledger\Enums\SettlementDirection;
use App\Modules\Ledger\Jobs\RenderSettlementStatement;
use App\Modules\Ledger\Models\Settlement;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Network\Services\PartnerAccounts;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Files\PrivateFileStore;
use App\Modules\Shared\Files\PrivatePaths;
use App\Modules\Shared\Files\StoredFile;
use App\Modules\Shared\Notifications\NotificationRecipient;
use App\Modules\Shared\Notifications\NotificationService;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The statement PDF sent to the partner (spec §7.8). Rendered once after the
 * settlement is built and kept in private storage; the entries it lists are
 * settled and never change.
 */
final class SettlementStatements
{
    public function __construct(
        private readonly PartnerAccounts $accounts,
        private readonly NetworkDirectory $network,
        private readonly PdfRenderer $pdfRenderer,
        private readonly PrivateFileStore $files,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** Idempotent: an already stored statement is neither rendered nor sent again. */
    public function publish(string $settlementId): void
    {
        $settlement = Settlement::query()->find($settlementId);

        if ($settlement === null || $settlement->statement_pdf_path !== null) {
            return;
        }

        $path = PrivatePaths::settlementStatement($settlement->id, $settlement->period_end);
        $stored = $this->files->exists($path)
            ? new StoredFile($path, $this->files->sha256($path), 0)
            : $this->files->putNew($path, $this->pdfRenderer->render($this->html($settlement)));

        $settlement->forceFill(['statement_pdf_path' => $stored->path])->save();
        $this->auditLogger->recordChanges('settlement.statement_stored', $settlement);
        $this->notifyPartner($settlement);
    }

    /** The PDF, or null while it is still being rendered (it is queued again in case the job was lost). */
    public function download(Settlement $settlement): ?StreamedResponse
    {
        if ($settlement->statement_pdf_path === null) {
            RenderSettlementStatement::dispatch($settlement->id, $settlement->organization_id);

            return null;
        }

        $this->auditLogger->record('settlement.statement_viewed', $settlement);

        return $this->files->download($settlement->statement_pdf_path, 'statement-'.str_replace(['/', '\\'], '-', $settlement->settlement_no).'.pdf');
    }

    public function html(Settlement $settlement): string
    {
        $account = $this->accounts->find($settlement->organization_id, ...$this->party($settlement))
            ?? throw new LogicException("Settlement {$settlement->id} has no partner.");

        return view('settlements.statement', ['document' => [
            'brand' => $this->network->organizationName($settlement->organization_id),
            'settlement' => $settlement,
            'partner' => $account,
            'agreement_no' => $account->terms?->agreementNo,
            'entries' => $settlement->entries()->get(),
        ]])->render();
    }

    private function notifyPartner(Settlement $settlement): void
    {
        $account = $this->accounts->find($settlement->organization_id, ...$this->party($settlement));

        if ($account === null) {
            return;
        }

        $this->notifications->notify(
            'settlement_ready',
            new NotificationRecipient($account->type->value, $account->id, $account->organizationId, $account->phone, $account->email, false),
            [
                'partner_name' => $account->name,
                'settlement_no' => $settlement->settlement_no,
                'period' => $settlement->period_start->format('d M Y').' to '.$settlement->period_end->format('d M Y'),
                'amount' => (string) $settlement->net_amount,
                'direction' => match ($settlement->direction) {
                    SettlementDirection::PartnerPaysHq => 'payable by you',
                    SettlementDirection::HqPaysPartner => 'payable to you',
                    SettlementDirection::Nil => 'nothing payable',
                },
            ],
        );
    }

    /** @return array{PartnerType, string} */
    private function party(Settlement $settlement): array
    {
        return $settlement->franchise_id !== null
            ? [PartnerType::Franchise, $settlement->franchise_id]
            : [PartnerType::B2bClient, (string) $settlement->b2b_client_id];
    }
}
