<?php

namespace Tests\Support\Ledger;

use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Network\Models\B2bClient;
use App\Modules\Network\Models\Franchise;
use App\Modules\Shared\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Partner money on the demo network: gateway webhooks for wallet top-ups and
 * invoice links, and ledger checks. Use with BooksOrders and BuildsStaff.
 */
trait MovesMoney
{
    protected const WEBHOOK_SECRET = 'test-webhook-secret';

    protected function useTestGatewaySecret(): void
    {
        config(['services.razorpay.webhook_secret' => self::WEBHOOK_SECRET, 'pathology.payments.gateway' => 'fake']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<JsonResponse>
     */
    protected function gatewayWebhook(array $payload, ?string $eventId = null): TestResponse
    {
        $body = (string) json_encode($payload);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RAZORPAY_EVENT_ID' => $eventId ?? 'evt_'.Str::random(12),
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ];

        return $this->call('POST', '/api/v1/webhooks/razorpay', server: $server, content: $body);
    }

    /** @return array<string, mixed> Razorpay's payment_link.paid for one of our links */
    protected function linkPaidPayload(string $noteKey, string $referenceId, string $linkId, int $amountPaise): array
    {
        return [
            'event' => 'payment_link.paid',
            'payload' => [
                'payment_link' => ['entity' => ['id' => $linkId, 'notes' => [$noteKey => $referenceId]]],
                'payment' => ['entity' => ['id' => 'pay_'.Str::random(10), 'amount' => $amountPaise, 'method' => 'upi', 'created_at' => now()->getTimestamp()]],
            ],
        ];
    }

    /** Requests a top-up as the given user and pays it through the gateway webhook. */
    protected function topUpWallet(string $amount, ?string $franchiseId = null): string
    {
        $topup = $this->postJson('/api/v1/wallet/topups', array_filter(['amount' => $amount, 'franchise_id' => $franchiseId]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data');

        $this->gatewayWebhook($this->linkPaidPayload('wallet_topup_id', $topup['id'], $topup['payment_link_id'], Money::fromString($amount)->paise()))->assertOk();

        return $topup['id'];
    }

    protected function franchiseId(string $code): string
    {
        return $this->asSystem(fn () => (string) Franchise::query()->where('franchise_code', $code)->value('id'));
    }

    protected function clientId(string $code): string
    {
        return $this->asSystem(fn () => (string) B2bClient::query()->where('client_code', $code)->value('id'));
    }

    protected function franchiseBalance(string $code): string
    {
        return $this->asSystem(fn () => (string) Franchise::query()->where('franchise_code', $code)->firstOrFail()->current_balance);
    }

    /** Spec §11.3: the ledger rows always add up to the cached balance, and each row's balance_after is the running total. */
    protected function assertLedgerAddsUp(string $column, string $partnerId, string $balance): void
    {
        $rows = $this->asSystem(fn () => LedgerEntry::query()->where($column, $partnerId)->orderBy('created_at')->orderBy('id')->get());
        $running = Money::zero();

        foreach ($rows as $row) {
            $running = $running->add($row->credit)->subtract($row->debit);
            $this->assertSame((string) $running, (string) $row->balance_after, "balance_after of {$row->narration}");
        }

        $this->assertSame($balance, (string) $running);
    }
}
