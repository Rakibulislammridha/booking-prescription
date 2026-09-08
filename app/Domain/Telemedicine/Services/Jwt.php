<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Services;

/**
 * The 30 lines of HS256 both self-hosted providers need. `firebase/php-jwt` is not installed and adding a
 * dependency for `hash_hmac` + base64url would be silly; this is deliberately the whole implementation.
 *
 * `encode()` is what LiveKit and Jitsi call a "JWT access token"; `decode()` exists so the suite can assert the
 * CLAIMS of a minted token (role, room, TTL, grants) rather than that a string is non-empty.
 */
final class Jwt
{
    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $extraHeaders
     */
    public static function encode(array $claims, string $secret, array $extraHeaders = []): string
    {
        $header = self::base64UrlEncode(self::json(['alg' => 'HS256', 'typ' => 'JWT', ...$extraHeaders]));
        $payload = self::base64UrlEncode(self::json($claims));
        $signature = self::base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    /**
     * Verifies the signature and (when present) `exp`/`nbf`, then returns the claims. Null on any failure —
     * callers never see a partially trusted token.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $token, string $secret, int $leewaySeconds = 0): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $claims = json_decode((string) self::base64UrlDecode($payload), true);

        if (! is_array($claims)) {
            return null;
        }

        $now = time();

        if (isset($claims['exp']) && is_numeric($claims['exp']) && $now > ((int) $claims['exp'] + $leewaySeconds)) {
            return null;
        }

        if (isset($claims['nbf']) && is_numeric($claims['nbf']) && $now < ((int) $claims['nbf'] - $leewaySeconds)) {
            return null;
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /**
     * Claims WITHOUT verifying the signature — for logging and for tests that assert on a tampered token.
     *
     * @return array<string, mixed>|null
     */
    public static function claimsOf(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $claims = json_decode((string) self::base64UrlDecode($parts[1]), true);

        return is_array($claims) ? $claims : null;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    /** @param  array<string, mixed>  $value */
    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
