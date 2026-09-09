<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * RFC 6238 TOTP in the profile every authenticator app assumes: HMAC-SHA1, 6 digits, a 30-second step
 * (ARCHITECTURE §6.5 — `pragmarx/google2fa` is deliberately NOT installed; this is the whole of it).
 *
 * Two details are the ones that actually matter:
 *
 *   · the counter is packed BIG-ENDIAN over 8 bytes (`pack('J', …)`, RFC 4226 §5.1). A little-endian pack
 *     produces codes that verify perfectly against themselves and against nothing else on earth, which is the
 *     classic way a hand-rolled TOTP passes its own tests and fails every phone;
 *   · comparison is `hash_equals`, not `===`. A six-digit code is small enough that a timing oracle is not
 *     theoretical, and constant-time comparison costs nothing.
 *
 * `verify()` returns the MATCHED TIME STEP rather than a boolean, because the caller has to remember which step
 * it accepted: TOTP's one structural hole is that a code stays valid for the rest of its window, so replay
 * protection is "this account has already spent step N" and it needs N. See SuperTwoFactor::markStepSpent().
 */
final class Totp
{
    public const ALGORITHM = 'sha1';

    public const DIGITS = 6;

    /** Seconds per time step. */
    public const PERIOD = 30;

    /** RFC 4648 base32 — no padding, which is what `otpauth://` URIs carry. */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 20 bytes = 160 bits = the HMAC-SHA1 block size RFC 4226 §4 R6 recommends; 32 base32 characters. */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes(max(10, $bytes)));
    }

    public static function stepAt(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    public static function codeForStep(#[SensitiveParameter] string $secret, int $step): string
    {
        $key = self::base32Decode($secret);

        if ($key === '') {
            throw new InvalidArgumentException('TOTP secret is empty or not base32');
        }

        $hash = hash_hmac(self::ALGORITHM, pack('J', $step), $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function code(#[SensitiveParameter] string $secret, ?int $timestamp = null): string
    {
        return self::codeForStep($secret, self::stepAt($timestamp));
    }

    /**
     * The time step the code belongs to, or null when it is not a code for this secret.
     *
     * `$window` is the tolerance in STEPS either side of now: 1 accepts a phone that is up to 30 s fast or slow,
     * which is the tolerance every mainstream implementation ships. Widening it multiplies the guess space a
     * brute-forcer gets per attempt, so it stays at 1 and the drift budget is spent on the rate limiter instead.
     */
    public static function verify(#[SensitiveParameter] string $secret, string $code, ?int $timestamp = null, int $window = 1): ?int
    {
        $code = (string) preg_replace('/\D/', '', $code);

        if (strlen($code) !== self::DIGITS || trim($secret) === '') {
            return null;
        }

        $now = self::stepAt($timestamp);

        for ($offset = -abs($window); $offset <= abs($window); $offset++) {
            if (hash_equals(self::codeForStep($secret, $now + $offset), $code)) {
                return $now + $offset;
            }
        }

        return null;
    }

    /** `otpauth://totp/Issuer:account?…` — the string a QR code carries (Google Authenticator's key URI format). */
    public static function provisioningUri(#[SensitiveParameter] string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGORITHM),
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Groups of four, which is how a human copies a secret off a screen when the camera will not focus. */
    public static function formatSecret(#[SensitiveParameter] string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function base32Encode(#[SensitiveParameter] string $bytes): string
    {
        $out = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($bytes) as $char) {
            $buffer = ($buffer << 8) | ord($char);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }

        return $bits > 0 ? $out.self::ALPHABET[($buffer << (5 - $bits)) & 31] : $out;
    }

    public static function base32Decode(#[SensitiveParameter] string $secret): string
    {
        $secret = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $secret));
        $out = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);

            if ($index === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $index;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $out;
    }
}
