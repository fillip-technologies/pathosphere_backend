<?php

namespace App\Modules\Locker\Infrastructure;

use App\Modules\Locker\Contracts\DigiLocker;
use App\Modules\Locker\Contracts\DigiLocker\DigiLockerAccess;
use App\Modules\Locker\Contracts\DigiLocker\DigiLockerDocument;
use App\Modules\Locker\Contracts\DigiLocker\DigiLockerFile;
use App\Modules\Locker\Errors\DigiLockerError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Stands in for DigiLocker until onboarding (DIGILOCKER_CLIENT=fake). It
 * behaves like the real flow, PKCE included: only the code it hands out
 * (CODE) is accepted, and only with the verifier whose challenge started
 * the sign-in. It holds a vaccination certificate (PDF) and a driving
 * licence (XML only, so it cannot be imported).
 */
final class FakeDigiLocker implements DigiLocker
{
    public const CODE = 'fake-digilocker-code';

    /** @var list<string> challenges of sign-ins started */
    private array $challenges = [];

    /** @var array<string, true> */
    private array $tokens = [];

    /** @var array<string, array{document: DigiLockerDocument, file: DigiLockerFile|null}> */
    private array $documents = [];

    private bool $unavailable = false;

    public function __construct()
    {
        $this->add(
            new DigiLockerDocument('in.gov.cowin-VACER-91234567890123', 'COVID-19 Vaccination Certificate', 'VACER', 'Ministry of Health and Family Welfare', CarbonImmutable::parse('2022-01-14'), ['application/pdf']),
            new DigiLockerFile("%PDF-1.4\n% fake vaccination certificate\n%%EOF\n", 'application/pdf'),
        );
        $this->add(
            new DigiLockerDocument('in.gov.transport-DRVLC-BR0120260001234', 'Driving Licence', 'DRVLC', 'Transport Department, Bihar', CarbonImmutable::parse('2020-06-01'), ['application/xml']),
            new DigiLockerFile('<Certificate/>', 'application/xml'),
        );
    }

    public function add(DigiLockerDocument $document, ?DigiLockerFile $file): void
    {
        $this->documents[$document->uri] = ['document' => $document, 'file' => $file];
    }

    /** The next calls fail as if DigiLocker were down. */
    public function goDown(): void
    {
        $this->unavailable = true;
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        $this->challenges[] = $codeChallenge;

        return 'https://digilocker.example.test/authorize?'.http_build_query(['state' => $state, 'code_challenge' => $codeChallenge, 'code_challenge_method' => 'S256']);
    }

    public function exchangeCode(string $code, string $codeVerifier): DigiLockerAccess
    {
        $this->assertUp();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        if ($code !== self::CODE || ! in_array($challenge, $this->challenges, true)) {
            throw DigiLockerError::authorizationFailed();
        }

        $token = 'fake-token-'.Str::random(20);
        $this->tokens[$token] = true;

        return new DigiLockerAccess($token, CarbonImmutable::now()->addHour(), 'FAKE-DL-0001', 'Asha Kumari');
    }

    public function issuedDocuments(DigiLockerAccess $access): array
    {
        $this->assertAuthorized($access);

        return array_values(array_map(fn (array $entry) => $entry['document'], $this->documents));
    }

    public function file(DigiLockerAccess $access, string $uri): DigiLockerFile
    {
        $this->assertAuthorized($access);

        return $this->documents[$uri]['file'] ?? throw DigiLockerError::documentNotFound();
    }

    private function assertAuthorized(DigiLockerAccess $access): void
    {
        $this->assertUp();

        if (! isset($this->tokens[$access->accessToken])) {
            throw DigiLockerError::authorizationFailed();
        }
    }

    private function assertUp(): void
    {
        if ($this->unavailable) {
            throw DigiLockerError::unavailable();
        }
    }
}
