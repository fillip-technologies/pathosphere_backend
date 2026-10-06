<?php

namespace Tests\Feature\Network;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\Region;
use App\Modules\Shared\Audit\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\TestCase;

/**
 * Franchise onboarding (spec §5.1) through the API: application, KYC papers
 * checked by the vendor and a person, the agreement with an exclusive
 * territory, e-sign, the fee and deposit on the ledger, go-live with the
 * first branch, suspension and termination.
 */
final class FranchiseOnboardingTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use RefreshDatabase;

    private const ESIGN_SECRET = 'test-esign-secret';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.esign.webhook_secret' => self::ESIGN_SECRET]);
        $this->setUpDemoNetwork();
        $this->manager = $this->staff(SystemRole::FranchiseManager);
    }

    public function test_a_franchise_is_onboarded_from_application_to_going_live(): void
    {
        $franchise = $this->apply()->assertCreated()->assertJsonPath('data.status', 'applied')->json('data');
        $this->assertSame('••••6789', $franchise['bank_account_no_masked']);

        // KYC papers: the vendor auto-checks PAN and bank proof; a person reviews all of them.
        $pan = $this->upload($franchise['id'], 'pan')->assertCreated()->json('data');
        $this->actingAsStaff($this->manager)->getJson("/api/v1/franchises/{$franchise['id']}")->assertJsonPath('data.status', 'kyc_pending');
        $this->actingAsStaff($this->manager)->getJson("/api/v1/franchise-documents/{$pan['id']}")
            ->assertJsonPath('data.status', 'auto_verified')
            ->assertJsonPath('data.vendor_reference', fn (string $reference) => str_starts_with($reference, 'kyc_fake_'));

        $address = $this->upload($franchise['id'], 'address_proof')->json('data');
        $this->review($address['id'], 'rejected', 'Blurred scan')->assertOk()->assertJsonPath('data.rejection_note', 'Blurred scan');
        $this->review($address['id'], 'verified')->assertStatus(409)->assertJsonPath('error.code', 'DOCUMENT_ALREADY_REVIEWED');

        $documents = [$pan, $this->upload($franchise['id'], 'address_proof')->json('data'), $this->upload($franchise['id'], 'bank_proof')->json('data')];
        foreach ($documents as $document) {
            $this->review($document['id'], 'verified')->assertOk();
        }
        $this->actingAsStaff($this->manager)->getJson("/api/v1/franchises/{$franchise['id']}")->assertJsonPath('data.status', 'kyc_pending');
        $this->review($this->upload($franchise['id'], 'premises_photo', 'shop.png')->json('data')['id'], 'verified')->assertOk();
        $this->actingAsStaff($this->manager)->getJson("/api/v1/franchises/{$franchise['id']}")->assertJsonPath('data.status', 'approved');

        // Viewing an identity paper is audited.
        $this->actingAsStaff($this->manager)->get("/api/v1/franchise-documents/{$pan['id']}/file")->assertOk();
        $this->assertTrue($this->asSystem(fn () => AuditLog::query()->where('action', 'franchise_document.viewed')->where('entity_id', $pan['id'])->exists()));

        // The agreement: Gaya already holds pincode 823001 exclusively.
        $terms = [
            'franchise_model' => 'psc', 'billing_model' => 'revenue_share', 'commission_pct' => '25',
            'franchise_fee' => '40000', 'security_deposit' => '15000',
            'settlement_cycle' => 'monthly', 'start_date' => '2026-11-01', 'end_date' => '2029-10-31',
        ];
        $this->actingAsStaff($this->manager)->postJson("/api/v1/franchises/{$franchise['id']}/agreements", $terms + ['territory_pincodes' => ['823001', '800001']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TERRITORY_CONFLICT')
            ->assertJsonPath('error.details.0.agreement_no', 'AGR-DEMO-FRGAYA')
            ->assertJsonPath('error.details.0.pincodes', ['823001']);
        $this->actingAsStaff($this->manager)->postJson("/api/v1/franchises/{$franchise['id']}/agreements", array_diff_key($terms, ['commission_pct' => true]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'commission_pct');
        $agreement = $this->actingAsStaff($this->manager)->postJson("/api/v1/franchises/{$franchise['id']}/agreements", $terms + ['territory_pincodes' => ['800001']])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.territory_pincodes', ['800001'])
            ->json('data');
        $this->assertMatchesRegularExpression('#^AGR/\d{2}-\d{2}/0001$#', $agreement['agreement_no']);

        $sent = $this->actingAsStaff($this->manager)->postJson("/api/v1/agreements/{$agreement['id']}/send-for-sign")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent_for_sign')
            ->assertJsonPath('data.approved_by', $this->manager->id)
            ->json('data');
        $etag = $this->actingAsStaff($this->manager)->getJson("/api/v1/agreements/{$agreement['id']}")->headers->get('ETag');
        $this->actingAsStaff($this->manager)->patchJson("/api/v1/agreements/{$agreement['id']}", ['commission_pct' => '35'], ['If-Match' => (string) $etag])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'AGREEMENT_NOT_EDITABLE');

        // The e-sign vendor's webhook activates it and posts the fee and deposit, once.
        $this->eSignWebhook(['reference' => $sent['esign_reference'], 'status' => 'signed'], 'sha256=forged')->assertStatus(401);
        $this->eSignWebhook(['reference' => $sent['esign_reference'], 'status' => 'signed'])->assertOk()->assertJsonPath('data.applied', true);
        $this->eSignWebhook(['reference' => $sent['esign_reference'], 'status' => 'signed'])->assertOk()->assertJsonPath('data.applied', false);

        $this->actingAsStaff($this->manager)->getJson("/api/v1/agreements/{$agreement['id']}")
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.signed_document_url', "/api/v1/agreements/{$agreement['id']}/document");
        $this->actingAsStaff($this->manager)->get("/api/v1/agreements/{$agreement['id']}/document")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame(
            [['franchise_fee', '40000.00', '0.00'], ['security_deposit', '0.00', '15000.00']],
            $this->asSystem(fn () => LedgerEntry::query()->where('franchise_id', $franchise['id'])->orderBy('created_at')->get()
                ->map(fn (LedgerEntry $row) => [$row->entry_type->value, (string) $row->debit, (string) $row->credit])->all()),
        );

        // Signed but no branch yet: still approved. The first branch takes it live.
        $this->actingAsStaff($this->manager)->getJson("/api/v1/franchises/{$franchise['id']}")->assertJsonPath('data.status', 'approved');
        $this->actingAsStaff($this->staff(SystemRole::HqOperations))->postJson('/api/v1/branches', [
            'branch_code' => 'PATFR1', 'name' => 'Patna Franchise PSC', 'branch_type' => 'psc', 'owner_type' => 'franchise',
            'franchise_id' => $franchise['id'], 'region_id' => $this->patnaRegionId(), 'address' => 'Fraser Road', 'pincode' => '800001', 'phone' => '9811100000',
        ])->assertCreated();
        $live = $this->actingAsStaff($this->manager)->getJson("/api/v1/franchises/{$franchise['id']}")->assertJsonPath('data.status', 'active')->json('data');
        $this->assertNotNull($live['onboarded_at']);

        // Suspension and reactivation by the franchise manager; termination is the Super Admin's.
        $this->actingAsStaff($this->manager)->postJson("/api/v1/franchises/{$franchise['id']}/suspend")->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->actingAsStaff($this->manager)->postJson("/api/v1/franchises/{$franchise['id']}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->actingAsStaff($this->manager)->postJson("/api/v1/franchises/{$franchise['id']}/terminate")->assertForbidden();
        $this->actingAsStaff($this->staff(SystemRole::SuperAdmin))->postJson("/api/v1/franchises/{$franchise['id']}/terminate")->assertOk()->assertJsonPath('data.status', 'terminated');
        $this->actingAsStaff($this->manager)->getJson("/api/v1/agreements/{$agreement['id']}")->assertJsonPath('data.status', 'terminated');
    }

    public function test_a_declined_signature_returns_the_agreement_to_draft_for_correction(): void
    {
        $franchiseId = $this->approvedFranchiseId();
        $agreement = $this->actingAsStaff($this->manager)->postJson("/api/v1/franchises/{$franchiseId}/agreements", [
            'franchise_model' => 'psc', 'billing_model' => 'wholesale', 'settlement_cycle' => 'weekly',
            'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
        ])->assertCreated()->assertJsonPath('data.commission_pct', null)->json('data');

        // Wholesale needs a partner price list on the franchise first.
        $this->actingAsStaff($this->manager)->postJson("/api/v1/agreements/{$agreement['id']}/send-for-sign")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PARTNER_PRICE_LIST_REQUIRED');

        $agreement = $this->actingAsStaff($this->manager)->patchJson(
            "/api/v1/agreements/{$agreement['id']}",
            ['billing_model' => 'revenue_share', 'commission_pct' => '20'],
            ['If-Match' => (string) $this->actingAsStaff($this->manager)->getJson("/api/v1/agreements/{$agreement['id']}")->headers->get('ETag')],
        )->assertOk()->json('data');
        $sent = $this->actingAsStaff($this->manager)->postJson("/api/v1/agreements/{$agreement['id']}/send-for-sign")->assertOk()->json('data');

        $this->eSignWebhook(['reference' => $sent['esign_reference'], 'status' => 'declined'])->assertOk()->assertJsonPath('data.applied', true);
        $this->actingAsStaff($this->manager)->getJson("/api/v1/agreements/{$agreement['id']}")
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.esign_reference', null);
        $this->assertSame(0, $this->asSystem(fn () => LedgerEntry::query()->where('franchise_id', $franchiseId)->count()));
    }

    public function test_records_rules_and_scope(): void
    {
        // Granting credit is head-office finance's call.
        $this->apply(['credit_limit' => '5000'])->assertForbidden()->assertJsonPath('error.code', 'CREDIT_LIMIT_NEEDS_FINANCE');

        $fresh = $this->apply(['franchise_code' => 'FRNEW'])->assertCreated()->json('data');
        $this->actingAsStaff($this->manager)->deleteJson("/api/v1/franchises/{$fresh['id']}")->assertNoContent();

        $withPapers = $this->apply(['franchise_code' => 'FRNEW2'])->json('data');
        $this->upload($withPapers['id'], 'pan')->assertCreated();
        $this->actingAsStaff($this->manager)->deleteJson("/api/v1/franchises/{$withPapers['id']}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FRANCHISE_NOT_DELETABLE');

        // A franchise owner sees its own franchise and papers, never another's.
        $gayaId = $this->asSystem(fn () => (string) Franchise::query()->where('franchise_code', 'FRGAYA')->value('id'));
        $owner = $this->staff(SystemRole::FranchiseOwner, ['franchise_id' => $gayaId]);
        $this->actingAsStaff($owner)->getJson('/api/v1/franchises')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $gayaId);
        $this->actingAsStaff($owner)->getJson("/api/v1/franchises/{$withPapers['id']}")->assertNotFound();
        $this->actingAsStaff($owner)->getJson("/api/v1/franchises/{$withPapers['id']}/documents")->assertNotFound();
        $this->actingAsStaff($owner)->postJson("/api/v1/franchises/{$gayaId}/agreements", [])->assertForbidden();
        $this->actingAsStaff($owner)->getJson("/api/v1/franchises/{$gayaId}/agreements")->assertOk()->assertJsonPath('data.0.billing_model', 'wholesale');

        // Front desks never see franchise records.
        $desk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('GAYPSC1')]);
        $this->actingAsStaff($desk)->getJson('/api/v1/franchises')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<JsonResponse>
     */
    private function apply(array $overrides = []): TestResponse
    {
        return $this->actingAsStaff($this->manager)->postJson('/api/v1/franchises', [
            'franchise_code' => 'FRPAT',
            'region_id' => $this->patnaRegionId(),
            'name' => 'Patliputra Diagnostics',
            'legal_name' => 'Patliputra Diagnostics LLP',
            'owner_name' => 'Ravi Shankar',
            'phone' => '9811122233',
            'email' => 'owner@patliputra.example',
            'pan' => 'ABCDE1234F',
            'address' => 'Boring Road, Patna',
            'bank_account_no' => '123456789',
            'bank_ifsc' => 'SBIN0001234',
            ...$overrides,
        ]);
    }

    /** @return TestResponse<JsonResponse> */
    private function upload(string $franchiseId, string $type, string $fileName = 'paper.pdf'): TestResponse
    {
        $file = str_ends_with($fileName, '.png') ? UploadedFile::fake()->image($fileName) : UploadedFile::fake()->create($fileName, 120, 'application/pdf');

        return $this->actingAsStaff($this->manager)->post("/api/v1/franchises/{$franchiseId}/documents", ['doc_type' => $type, 'file' => $file], ['Accept' => 'application/json']);
    }

    /** @return TestResponse<JsonResponse> */
    private function review(string $documentId, string $decision, ?string $note = null): TestResponse
    {
        return $this->actingAsStaff($this->manager)->postJson("/api/v1/franchise-documents/{$documentId}/verify", array_filter(['decision' => $decision, 'rejection_note' => $note]));
    }

    /**
     * @param  array<string, string>  $payload
     * @return TestResponse<JsonResponse>
     */
    private function eSignWebhook(array $payload, ?string $signature = null): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/esign', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature ?? 'sha256='.hash_hmac('sha256', $body, self::ESIGN_SECRET),
        ], content: $body);
    }

    private function approvedFranchiseId(): string
    {
        $franchiseId = $this->apply()->json('data.id');

        foreach (['pan', 'address_proof', 'bank_proof', 'premises_photo'] as $type) {
            $this->review($this->upload($franchiseId, $type)->json('data.id'), 'verified')->assertOk();
        }

        return $franchiseId;
    }

    private function patnaRegionId(): string
    {
        return $this->asSystem(fn () => (string) Region::query()->where('name', 'Patna')->value('id'));
    }
}
