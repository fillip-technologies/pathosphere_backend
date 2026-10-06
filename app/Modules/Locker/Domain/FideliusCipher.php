<?php

namespace App\Modules\Locker\Domain;

use InvalidArgumentException;
use RuntimeException;

/**
 * Encryption of health data shared through ABDM (spec §5.7 M2, ABDM's
 * "Fidelius" scheme): ECDH on Curve25519 between our one-time key and the
 * receiver's, both sides contributing a 32-byte nonce.
 *
 *   shared = X25519(our private key, their public key)
 *   xor    = our nonce XOR their nonce
 *   key    = HKDF-SHA256(shared, salt = first 20 bytes of xor), 32 bytes
 *   iv     = last 12 bytes of xor
 *   data   = base64(AES-256-GCM(plain) || 16-byte tag)
 *
 * Keys here are raw 32-byte X25519 keys in base64. ABDM's reference
 * implementation may exchange keys in another encoding; the encoding must be
 * confirmed against the sandbox (BUILD_PLAN Phase 8), the scheme itself
 * stays the same.
 */
final class FideliusCipher
{
    public const ALGORITHM = 'ECDH';

    public const CURVE = 'Curve25519';

    private const KEY_BYTES = 32;

    private const NONCE_BYTES = 32;

    private const TAG_BYTES = 16;

    /** @return array{private: string, public: string} base64 */
    public static function keyPair(): array
    {
        $pair = sodium_crypto_box_keypair();

        return [
            'private' => base64_encode(sodium_crypto_box_secretkey($pair)),
            'public' => base64_encode(sodium_crypto_box_publickey($pair)),
        ];
    }

    /** Base64 of 32 random bytes. */
    public static function nonce(): string
    {
        return base64_encode(random_bytes(self::NONCE_BYTES));
    }

    /** Whether a public key and nonce from the other side can be used. */
    public static function accepts(string $publicKey, string $nonce): bool
    {
        return strlen((string) base64_decode($publicKey, true)) === self::KEY_BYTES
            && strlen((string) base64_decode($nonce, true)) === self::NONCE_BYTES;
    }

    public static function encrypt(string $plain, string $ourPrivateKey, string $theirPublicKey, string $ourNonce, string $theirNonce): string
    {
        [$key, $iv] = self::keyAndIv($ourPrivateKey, $theirPublicKey, $ourNonce, $theirNonce);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);

        if ($cipher === false) {
            throw new RuntimeException('Health data could not be encrypted.');
        }

        return base64_encode($cipher.$tag);
    }

    /** The receiver's side: used by tests and, later, to read records pulled from other providers. */
    public static function decrypt(string $encrypted, string $ourPrivateKey, string $theirPublicKey, string $ourNonce, string $theirNonce): string
    {
        $bytes = (string) base64_decode($encrypted, true);

        if (strlen($bytes) < self::TAG_BYTES) {
            throw new InvalidArgumentException('The encrypted data is too short.');
        }

        [$key, $iv] = self::keyAndIv($ourPrivateKey, $theirPublicKey, $ourNonce, $theirNonce);
        $plain = openssl_decrypt(substr($bytes, 0, -self::TAG_BYTES), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, substr($bytes, -self::TAG_BYTES));

        if ($plain === false) {
            throw new InvalidArgumentException('The encrypted data could not be decrypted with these keys.');
        }

        return $plain;
    }

    /** @return array{0: string, 1: string} AES key and IV */
    private static function keyAndIv(string $privateKey, string $publicKey, string $nonceA, string $nonceB): array
    {
        $private = (string) base64_decode($privateKey, true);
        $public = (string) base64_decode($publicKey, true);
        $a = (string) base64_decode($nonceA, true);
        $b = (string) base64_decode($nonceB, true);

        if (strlen($private) !== self::KEY_BYTES || strlen($public) !== self::KEY_BYTES) {
            throw new InvalidArgumentException('Keys must be 32-byte Curve25519 keys in base64.');
        }

        if (strlen($a) !== self::NONCE_BYTES || strlen($b) !== self::NONCE_BYTES) {
            throw new InvalidArgumentException('Nonces must be 32 bytes in base64.');
        }

        // XOR is symmetric, so both sides get the same salt and IV whichever nonce is "ours".
        $xor = $a ^ $b;
        $shared = sodium_crypto_scalarmult($private, $public);

        return [hash_hkdf('sha256', $shared, 32, '', substr($xor, 0, 20)), substr($xor, -12)];
    }
}
