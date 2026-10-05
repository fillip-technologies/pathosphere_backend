<?php

namespace Tests\Feature\Booking;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Booking\Models\Order;
use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Catalogue\Models\Package;
use App\Modules\Network\Models\Branch;
use App\Modules\Shared\Notifications\Contracts\EmailSender;
use App\Modules\Shared\Notifications\Contracts\SmsSender;
use App\Modules\Shared\Notifications\Contracts\WhatsAppSender;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Fakes\RecordingMessageSender;
use Tests\TestCase;

/**
 * Phase 3 "done when" (spec §12): a company-owned PSC books, bills and
 * collects payment end to end — at the desk and online through a payment
 * link confirmed by the gateway webhook.
 */
final class BookingFlowTest extends TestCase
{
    use BuildsStaff;
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-webhook-secret';

    private RecordingMessageSender $messages;

    private User $frontDesk;

    private string $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.razorpay.webhook_secret' => self::WEBHOOK_SECRET, 'pathology.payments.gateway' => 'fake']);
        $this->seed(DatabaseSeeder::class);
        $this->useSeededOrganization();

        $this->messages = new RecordingMessageSender;
        $this->app->instance(SmsSender::class, $this->messages);
        $this->app->instance(WhatsAppSender::class, $this->messages);
        $this->app->instance(EmailSender::class, $this->messages);

        $this->branchId = $this->asSystem(fn () => Branch::query()->where('branch_code', 'PATPSC1')->value('id'));
        $this->frontDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId]);
        $this->actingAsStaff($this->frontDesk);
    }

    public function test_walk_in_registers_books_pays_at_the_desk_and_gets_a_confirmation(): void
    {
        $patient = $this->registerPatient(whatsappOptIn: true);
        $this->assertMatchesRegularExpression('/^UH\d{8}$/', $patient['uhid']);

        $order = $this->book($patient['id'], [$this->test('CBC'), $this->package('PKG-THY')], payment: ['mode' => 'upi', 'amount' => '949.00', 'transaction_id' => 'UPI-REF-1'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.invoices.0.total', '949.00')
            ->assertJsonPath('data.invoices.0.payment_status', 'paid')
            ->assertJsonPath('data.invoices.0.payments.0.mode', 'upi')
            ->json('data');

        $this->assertMatchesRegularExpression('#^INV/PATPSC1/\d{2}-\d{2}/00001$#', $order['invoices'][0]['invoice_no']);
        $this->assertStringStartsWith('PATPSC1-', $order['order_no']);

        // CBC line + package line + 3 package child tests, children at price 0.
        $this->assertCount(5, $order['items']);
        $children = array_filter($order['items'], fn (array $item) => $item['parent_item_id'] !== null);
        $this->assertSame(['0.00'], array_values(array_unique(array_column($children, 'mrp_price'))));

        // Booking confirmation went out on WhatsApp (patient opted in), after commit.
        $this->assertSame(['whatsapp'], $this->messages->channelsUsed());
        $this->assertStringContainsString($order['order_no'], $this->messages->sent[0]['body']);
    }

    public function test_a_partly_paid_walk_in_stays_draft_until_paid_in_full(): void
    {
        $patient = $this->registerPatient();

        $order = $this->book($patient['id'], [$this->test('CBC')], payment: ['mode' => 'cash', 'amount' => '100.00'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.invoices.0.payment_status', 'partially_paid')
            ->assertJsonPath('data.invoices.0.balance_due', '250.00')
            ->json('data');

        $this->assertSame([], $this->messages->sent);

        $this->postJson("/api/v1/invoices/{$order['invoices'][0]['id']}/payments", ['mode' => 'cash', 'amount' => '300.00'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PAYMENT_EXCEEDS_BALANCE');

        $this->postJson("/api/v1/invoices/{$order['invoices'][0]['id']}/payments", ['mode' => 'cash', 'amount' => '250.00'])->assertCreated();

        $this->getJson("/api/v1/orders/{$order['id']}")
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.invoices.0.payment_status', 'paid');

        // SMS, because this patient did not opt in to WhatsApp.
        $this->assertSame(['sms'], $this->messages->channelsUsed());
    }

    public function test_online_booking_is_confirmed_by_the_gateway_webhook_only_once(): void
    {
        $patient = $this->registerPatient();

        $order = $this->book($patient['id'], [$this->test('LIPID')], source: 'online')
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data');
        $invoiceId = $order['invoices'][0]['id'];

        $link = $this->postJson('/api/v1/payment-links', ['invoice_id' => $invoiceId])
            ->assertOk()
            ->assertJsonPath('data.amount', '600.00')
            ->json('data');
        $this->assertStringContainsString($link['url'], $this->messages->sent[0]['body']);

        $payload = json_encode($this->paymentLinkPaidPayload($invoiceId, $link['link_id'], 60000));
        $headers = ['X-Razorpay-Event-Id' => 'evt_1', 'X-Razorpay-Signature' => hash_hmac('sha256', (string) $payload, self::WEBHOOK_SECRET)];

        $this->call('POST', '/api/v1/webhooks/razorpay', server: $this->serverHeaders($headers), content: $payload)
            ->assertOk()
            ->assertJsonPath('data.duplicate', false);

        // The gateway retries the same event: stored once, applied once.
        $this->call('POST', '/api/v1/webhooks/razorpay', server: $this->serverHeaders($headers), content: $payload)
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);

        $this->actingAsStaff($this->frontDesk)->getJson("/api/v1/orders/{$order['id']}")
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.invoices.0.payment_status', 'paid')
            ->assertJsonCount(1, 'data.invoices.0.payments')
            ->assertJsonPath('data.invoices.0.payments.0.gateway', 'razorpay');
    }

    public function test_a_forged_webhook_is_stored_but_never_applied(): void
    {
        $patient = $this->registerPatient();
        $order = $this->book($patient['id'], [$this->test('LIPID')], source: 'online')->json('data');
        $payload = json_encode($this->paymentLinkPaidPayload($order['invoices'][0]['id'], 'plink_x', 60000));

        $this->call('POST', '/api/v1/webhooks/razorpay', server: $this->serverHeaders(['X-Razorpay-Event-Id' => 'evt_forged', 'X-Razorpay-Signature' => 'bogus']), content: $payload)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_SIGNATURE');

        $this->assertDatabaseHas('payment_webhook_events', ['event_id' => 'evt_forged', 'signature_valid' => false]);
        $this->actingAsStaff($this->frontDesk)->getJson("/api/v1/orders/{$order['id']}")->assertJsonPath('data.status', 'draft');
    }

    public function test_retrying_a_booking_with_the_same_idempotency_key_creates_one_order(): void
    {
        $patient = $this->registerPatient();
        $key = (string) Str::uuid();

        $first = $this->book($patient['id'], [$this->test('CBC')], payment: ['mode' => 'cash', 'amount' => '350.00'], idempotencyKey: $key)->assertCreated();
        $retry = $this->book($patient['id'], [$this->test('CBC')], payment: ['mode' => 'cash', 'amount' => '350.00'], idempotencyKey: $key)->assertCreated();

        $this->assertSame($first->json('data.id'), $retry->json('data.id'));
        $retry->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, $this->asSystem(fn () => Order::query()->count()));
    }

    /** @return array<string, mixed> */
    private function registerPatient(bool $whatsappOptIn = false): array
    {
        return $this->postJson('/api/v1/patients', [
            'name' => 'Asha Kumari',
            'gender' => 'female',
            'age_years' => 34,
            'phone' => '9876501234',
            'consent_notice_version' => '2026-10',
            'whatsapp_opt_in' => $whatsappOptIn,
        ])->assertCreated()->json('data');
    }

    /**
     * @param  list<array<string, string>>  $items
     * @param  array<string, string>|null  $payment
     * @return TestResponse<JsonResponse>
     */
    private function book(string $patientId, array $items, ?array $payment = null, string $source = 'walk_in', ?string $idempotencyKey = null): TestResponse
    {
        return $this->postJson('/api/v1/orders', array_filter([
            'patient_id' => $patientId,
            'branch_id' => $this->branchId,
            'order_source' => $source,
            'items' => $items,
            'payment' => $payment,
        ]), $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey]);
    }

    /** @return array<string, mixed> */
    private function paymentLinkPaidPayload(string $invoiceId, string $linkId, int $amountPaise): array
    {
        return [
            'event' => 'payment_link.paid',
            'payload' => [
                'payment_link' => ['entity' => ['id' => $linkId, 'notes' => ['invoice_id' => $invoiceId]]],
                'payment' => ['entity' => ['id' => 'pay_'.Str::random(10), 'amount' => $amountPaise, 'method' => 'upi', 'created_at' => time()]],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    /** @return array{test_id: string} */
    private function test(string $code): array
    {
        return ['test_id' => $this->asSystem(fn () => LabTest::query()->where('code', $code)->value('id'))];
    }

    /** @return array{package_id: string} */
    private function package(string $code): array
    {
        return ['package_id' => $this->asSystem(fn () => Package::query()->where('code', $code)->value('id'))];
    }
}
