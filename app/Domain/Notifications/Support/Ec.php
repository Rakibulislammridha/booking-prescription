<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * P-256 key plumbing for Web Push. Web Push speaks raw key material — a 65-byte uncompressed point and a 32-byte
 * private scalar, both base64url — while OpenSSL speaks PEM, so this class converts between them by assembling the
 * (fixed-shape) DER structures by hand. No composer package is involved: `ext-openssl` and `hash_hkdf` are enough.
 */
final class Ec
{
    /** SubjectPublicKeyInfo prefix for id-ecPublicKey + prime256v1, followed by a 66-byte BIT STRING. */
    private const SPKI_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }

    /** PEM for a public key given the raw 65-byte uncompressed point (0x04 || X || Y). */
    public static function publicKeyPem(string $point): string
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new RuntimeException('web push: public key must be a 65-byte uncompressed P-256 point');
        }

        return self::pem('PUBLIC KEY', self::SPKI_PREFIX.$point);
    }

    /**
     * PEM for a private key given the raw 32-byte scalar and its public point — RFC 5915 ECPrivateKey:
     * SEQUENCE { INTEGER 1, OCTET STRING(32) d, [0] OID prime256v1, [1] BIT STRING publicKey }.
     */
    public static function privateKeyPem(string $scalar, string $point): string
    {
        if (strlen($scalar) !== 32) {
            throw new RuntimeException('web push: private key must be a 32-byte P-256 scalar');
        }

        $der = "\x30\x77"                                        // SEQUENCE, 0x77 bytes
            ."\x02\x01\x01"                                       // INTEGER 1
            ."\x04\x20".$scalar                                   // OCTET STRING (32) private scalar
            ."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"   // [0] OID prime256v1
            ."\xa1\x44\x03\x42\x00".$point;                       // [1] BIT STRING (66) public point

        return self::pem('EC PRIVATE KEY', $der);
    }

    /** The raw 65-byte uncompressed point of an OpenSSL EC key. */
    public static function pointOf(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('web push: key is not an EC key');
        }

        return "\x04".str_pad((string) $details['ec']['x'], 32, "\x00", STR_PAD_LEFT).str_pad((string) $details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    }

    /**
     * Key generation is the ONE openssl call that needs an `openssl.cnf`, and minimal PHP builds (herd-lite, slim
     * containers) are frequently compiled with a default path that does not exist. Reading and deriving keys is
     * unaffected, so the retry is confined here: try the compiled-in default first, then the usual locations.
     *
     * @return array{0: OpenSSLAsymmetricKey, 1: string} the key and its raw public point
     */
    public static function generate(): array
    {
        $args = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = @openssl_pkey_new($args);

        foreach (self::configCandidates() as $config) {
            if ($key !== false) {
                break;
            }

            $key = @openssl_pkey_new([...$args, 'config' => $config]);
        }

        if ($key === false) {
            throw new RuntimeException('web push: could not generate a P-256 key pair (no usable openssl.cnf)');
        }

        return [$key, self::pointOf($key)];
    }

    /** @return array<int, string> */
    private static function configCandidates(): array
    {
        $candidates = [(string) getenv('OPENSSL_CONF'), '/etc/ssl/openssl.cnf', '/usr/lib/ssl/openssl.cnf', '/usr/local/etc/openssl/openssl.cnf', '/etc/pki/tls/openssl.cnf'];

        return array_values(array_filter($candidates, fn (string $path): bool => $path !== '' && is_readable($path)));
    }

    /** ECDSA DER signature → the fixed 64-byte r||s JOSE form ES256 requires. */
    public static function derToJose(string $der): string
    {
        $offset = 3 + ord($der[3]);
        $rLength = ord($der[3]);
        $r = substr($der, 4, $rLength);
        $sLength = ord($der[$offset + 2]);
        $s = substr($der, $offset + 3, $sLength);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT).str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }

    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$label}-----\n";
    }
}
