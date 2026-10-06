<?php

namespace App\Modules\Locker\Infrastructure;

use App\Modules\Locker\Contracts\DigiLocker;
use App\Modules\Locker\Contracts\DigiLocker\DigiLockerAccess;
use App\Modules\Locker\Contracts\DigiLocker\DigiLockerDocument;
use App\Modules\Locker\Contracts\DigiLocker\DigiLockerFile;
use App\Modules\Locker\Errors\DigiLockerError;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DigiLocker's Authorized Partner API (via API Setu), written from its
 * published specification: authorization code with PKCE (S256), token at
 * oauth2/1/token, issued documents at oauth2/2/files/issued, a file at
 * oauth2/1/file/{uri}. Paths, field names and the date format must be
 * confirmed against the sandbox at onboarding, as for ABDM.
 */
final class ApiSetuDigiLocker implements DigiLocker
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly int $timeoutSeconds = 15,
    ) {}

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return $this->url('public/oauth2/1/authorize').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchangeCode(string $code, string $codeVerifier): DigiLockerAccess
    {
        $response = $this->send(fn () => $this->http()->asForm()->post($this->url('public/oauth2/1/token'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]));

        if ($response->clientError()) {
            throw DigiLockerError::authorizationFailed();
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw DigiLockerError::unavailable();
        }

        return new DigiLockerAccess(
            $token,
            CarbonImmutable::now()->addSeconds(max(60, (int) $response->json('expires_in', 3600))),
            (string) $response->json('digilockerid', ''),
            is_string($response->json('name')) ? $response->json('name') : null,
        );
    }

    public function issuedDocuments(DigiLockerAccess $access): array
    {
        $response = $this->authorized($access, fn (PendingRequest $http) => $http->get($this->url('public/oauth2/2/files/issued')));
        $items = $response->json('items');

        if (! is_array($items)) {
            return [];
        }

        $documents = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['uri'] ?? null) || $item['uri'] === '') {
                continue;
            }

            $documents[] = new DigiLockerDocument(
                $item['uri'],
                (string) ($item['description'] ?? $item['name'] ?? $item['uri']),
                isset($item['doctype']) ? (string) $item['doctype'] : null,
                isset($item['issuer']) ? (string) $item['issuer'] : null,
                $this->date($item['date'] ?? null),
                array_values(array_map('strval', (array) ($item['mime'] ?? []))),
            );
        }

        return $documents;
    }

    public function file(DigiLockerAccess $access, string $uri): DigiLockerFile
    {
        $response = $this->authorized($access, fn (PendingRequest $http) => $http->get($this->url('public/oauth2/1/file/'.rawurlencode($uri))));

        return new DigiLockerFile($response->body(), (string) $response->header('Content-Type'));
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function authorized(DigiLockerAccess $access, callable $call): Response
    {
        $response = $this->send(fn () => $call($this->http()->withToken($access->accessToken)));

        if ($response->status() === 404) {
            throw DigiLockerError::documentNotFound();
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw DigiLockerError::authorizationFailed();
        }

        return $response;
    }

    /** @param  callable(): Response  $call */
    private function send(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw DigiLockerError::unavailable();
        }

        if ($response->serverError()) {
            throw DigiLockerError::unavailable();
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout($this->timeoutSeconds);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.$path;
    }

    /** DigiLocker lists dates as DD-MM-YYYY; anything unreadable is left out. */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        foreach (['!d-m-Y', '!Y-m-d', '!d/m/Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date instanceof CarbonImmutable) {
                return $date;
            }
        }

        return null;
    }
}
