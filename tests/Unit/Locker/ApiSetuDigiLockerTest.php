<?php

namespace Tests\Unit\Locker;

use App\Modules\Locker\Contracts\DigiLocker\DigiLockerAccess;
use App\Modules\Locker\Errors\DigiLockerError;
use App\Modules\Locker\Infrastructure\ApiSetuDigiLocker;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The DigiLocker partner API as we call it (spec §3); to be confirmed against the sandbox at onboarding. */
final class ApiSetuDigiLockerTest extends TestCase
{
    private ApiSetuDigiLocker $digiLocker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->digiLocker = new ApiSetuDigiLocker('https://dl.example.test/', 'client-1', 'secret-1', 'pathapp://digilocker');
    }

    public function test_the_patient_is_sent_to_digilocker_with_pkce(): void
    {
        $url = $this->digiLocker->authorizationUrl('state-1', 'challenge-1');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://dl.example.test/public/oauth2/1/authorize?', $url);
        $this->assertSame([
            'response_type' => 'code',
            'client_id' => 'client-1',
            'redirect_uri' => 'pathapp://digilocker',
            'state' => 'state-1',
            'code_challenge' => 'challenge-1',
            'code_challenge_method' => 'S256',
        ], $query);
    }

    public function test_the_code_is_exchanged_with_the_verifier(): void
    {
        Http::fake(['dl.example.test/public/oauth2/1/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600, 'digilockerid' => 'DL-9', 'name' => 'Asha Kumari'])]);

        $access = $this->digiLocker->exchangeCode('code-1', 'verifier-1');

        $this->assertSame(['tok-1', 'DL-9', 'Asha Kumari'], [$access->accessToken, $access->digiLockerId, $access->name]);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'code-1'
            && $request['code_verifier'] === 'verifier-1'
            && $request['client_secret'] === 'secret-1');
    }

    public function test_issued_documents_are_read_and_odd_entries_skipped(): void
    {
        Http::fake(['dl.example.test/public/oauth2/2/files/issued' => Http::response(['items' => [
            ['name' => 'Vaccination', 'description' => 'COVID-19 Vaccination Certificate', 'uri' => 'in.gov.cowin-VACER-1', 'doctype' => 'VACER', 'issuer' => 'MoHFW', 'date' => '14-01-2022', 'mime' => ['application/pdf']],
            ['name' => 'No URI'],
            ['name' => 'Odd date', 'uri' => 'in.gov.x-Y-2', 'date' => 'soon'],
        ]])]);

        $documents = $this->digiLocker->issuedDocuments($this->access());

        $this->assertCount(2, $documents);
        $this->assertSame(['in.gov.cowin-VACER-1', 'COVID-19 Vaccination Certificate', 'VACER', 'MoHFW', '2022-01-14', ['application/pdf']], [
            $documents[0]->uri, $documents[0]->name, $documents[0]->docType, $documents[0]->issuer, $documents[0]->issuedOn?->toDateString(), $documents[0]->mimeTypes,
        ]);
        $this->assertNull($documents[1]->issuedOn);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer tok-1'));
    }

    public function test_a_file_is_fetched_by_its_uri(): void
    {
        Http::fake(['dl.example.test/public/oauth2/1/file/*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf'])]);

        $file = $this->digiLocker->file($this->access(), 'in.gov.cowin-VACER-1');

        $this->assertSame(['%PDF-1.4', 'application/pdf'], [$file->contents, $file->mimeType]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://dl.example.test/public/oauth2/1/file/in.gov.cowin-VACER-1');
    }

    public function test_refusals_and_outages_become_our_errors(): void
    {
        Http::fake([
            'dl.example.test/public/oauth2/1/token' => Http::response(['error' => 'invalid_grant'], 400),
            'dl.example.test/public/oauth2/2/files/issued' => Http::response('', 502),
            'dl.example.test/public/oauth2/1/file/*' => Http::response('', 404),
        ]);

        $this->assertErrorCode('DIGILOCKER_AUTHORIZATION_FAILED', fn () => $this->digiLocker->exchangeCode('used', 'v'));
        $this->assertErrorCode('DIGILOCKER_UNAVAILABLE', fn () => $this->digiLocker->issuedDocuments($this->access()));
        $this->assertErrorCode('DIGILOCKER_DOCUMENT_NOT_FOUND', fn () => $this->digiLocker->file($this->access(), 'gone'));
    }

    private function access(): DigiLockerAccess
    {
        return new DigiLockerAccess('tok-1', CarbonImmutable::now()->addHour(), 'DL-9', null);
    }

    private function assertErrorCode(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$code}.");
        } catch (DigiLockerError $error) {
            $this->assertSame($code, $error->errorCode);
        }
    }
}
