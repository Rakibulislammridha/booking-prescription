<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Support\Ec;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * RFC 8291 "Message Encryption for Web Push" with the aes128gcm content encoding of RFC 8188.
 *
 *   ecdh_secret = ECDH(as_private, ua_public)
 *   PRK_key     = HKDF(salt = auth_secret, ikm = ecdh_secret, info = "WebPush: info\0" || ua_public || as_public, 32)
 *   PRK         = HKDF-extract(salt, PRK_key)      -- both derived in one hash_hkdf call below
 *   CEK         = HKDF(salt, PRK_key, "Content-Encoding: aes128gcm\0", 16)
 *   NONCE       = HKDF(salt, PRK_key, "Content-Encoding: nonce\0", 12)
 *   body        = salt(16) || rs(4) || idlen(1) || as_public(65) || AES-128-GCM(CEK, NONCE, plaintext || 0x02)
 *
 * The ephemeral key pair and the salt are injectable so the implementation can be pinned to the RFC's own test
 * vector — encryption you cannot test against a published vector is encryption you are guessing at.
 */
final class WebPushEncryptor
{
    public const RECORD_SIZE = 4096;

    /**
     * @param  string  $userPublicKey  raw 65-byte uncompressed point (the subscription's `p256dh`)
     * @param  string  $authSecret  raw 16-byte subscription `auth`
     * @return string the aes128gcm request body
     */
    public function encrypt(string $plaintext, string $userPublicKey, string $authSecret, ?OpenSSLAsymmetricKey $ephemeral = null, ?string $salt = null): string
    {
        if (strlen($userPublicKey) !== 65) {
            throw new RuntimeException('web push: p256dh must decode to 65 bytes');
        }

        if ($ephemeral === null) {
            [$ephemeral] = Ec::generate();
        }

        $serverPublic = Ec::pointOf($ephemeral);
        $salt ??= random_bytes(16);

        $peer = openssl_pkey_get_public(Ec::publicKeyPem($userPublicKey));

        if ($peer === false) {
            throw new RuntimeException('web push: could not read the subscription public key');
        }

        $sharedSecret = openssl_pkey_derive($peer, $ephemeral, 32);

        if ($sharedSecret === false) {
            throw new RuntimeException('web push: ECDH derivation failed');
        }

        $ikm = hash_hkdf('sha256', $sharedSecret, 32, "WebPush: info\x00".$userPublicKey.$serverPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $tag = '';
        $ciphertext = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('web push: AES-128-GCM encryption failed');
        }

        return $salt.pack('N', self::RECORD_SIZE).chr(strlen($serverPublic)).$serverPublic.$ciphertext.$tag;
    }
}
