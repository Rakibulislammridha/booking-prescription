<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Domain\Notifications\Services\VapidSigner;
use App\Domain\Notifications\Services\WebPushEncryptor;
use App\Domain\Notifications\Support\Ec;
use PHPUnit\Framework\TestCase;

/**
 * Web Push encryption pinned to the published test vector of RFC 8291 §5. Encryption that is only tested against
 * itself is encryption you are guessing at: if this passes, a real browser can decrypt what we send.
 *
 * The ephemeral key pair and the salt are the RFC's, injected, because they are random in production.
 */
final class WebPushEncryptionTest extends TestCase
{
    // RFC 8291 §5 "Push Message Encryption Example"
    private const PLAINTEXT = 'When I grow up, I want to be a watermelon';

    private const UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

    private const AUTH_SECRET = 'BTBZMqHH6r4Tts7J_aSIgg';

    private const AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';

    private const AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';

    private const SALT = 'DGv6ra1nlYgDCS1FRnbzlw';

    private const EXPECTED_BODY = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27ml'
        .'mlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPT'
        .'pK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    public function test_encrypts_the_rfc_8291_vector_byte_for_byte(): void
    {
        $encryptor = new WebPushEncryptor;

        $ephemeral = openssl_pkey_get_private(Ec::privateKeyPem(
            Ec::base64UrlDecode(self::AS_PRIVATE),
            Ec::base64UrlDecode(self::AS_PUBLIC),
        ));

        $this->assertNotFalse($ephemeral, 'the RFC ephemeral key must load as a P-256 private key');

        $body = $encryptor->encrypt(
            self::PLAINTEXT,
            Ec::base64UrlDecode(self::UA_PUBLIC),
            Ec::base64UrlDecode(self::AUTH_SECRET),
            $ephemeral,
            Ec::base64UrlDecode(self::SALT),
        );

        $this->assertSame(self::EXPECTED_BODY, Ec::base64UrlEncode($body));
    }

    public function test_the_body_carries_the_aes128gcm_header_the_push_service_expects(): void
    {
        $encryptor = new WebPushEncryptor;

        $body = $encryptor->encrypt('hello', Ec::base64UrlDecode(self::UA_PUBLIC), Ec::base64UrlDecode(self::AUTH_SECRET));

        // salt(16) || record size(4, big-endian) || key id length(1) || the server's 65-byte public key || ciphertext
        $this->assertSame(4096, unpack('N', substr($body, 16, 4))[1]);
        $this->assertSame(65, ord($body[20]));
        $this->assertSame("\x04", $body[21], 'the embedded key must be an uncompressed P-256 point');
        $this->assertGreaterThan(86, strlen($body));
    }

    public function test_a_random_salt_makes_every_ciphertext_different(): void
    {
        $encryptor = new WebPushEncryptor;
        $ua = Ec::base64UrlDecode(self::UA_PUBLIC);
        $auth = Ec::base64UrlDecode(self::AUTH_SECRET);

        $this->assertNotSame($encryptor->encrypt('hello', $ua, $auth), $encryptor->encrypt('hello', $ua, $auth));
    }

    public function test_vapid_header_is_an_es256_jwt_bound_to_the_push_service_origin(): void
    {
        [$key, $point] = Ec::generate();
        $details = openssl_pkey_get_details($key);
        $this->assertNotFalse($details);

        $signer = new VapidSigner(
            Ec::base64UrlEncode($point),
            Ec::base64UrlEncode(str_pad((string) $details['ec']['d'], 32, "\x00", STR_PAD_LEFT)),
            'mailto:ops@example.test',
        );

        $this->assertTrue($signer->isConfigured());

        $headers = $signer->headers('https://fcm.googleapis.com/fcm/send/abc123?x=1', now: 1_800_000_000);
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('vapid t=', $headers['Authorization']);

        preg_match('/^vapid t=([^,]+), k=(.+)$/', $headers['Authorization'], $m);
        [$header, $payload, $signature] = explode('.', $m[1]);

        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(Ec::base64UrlDecode($header), true));
        $claims = json_decode(Ec::base64UrlDecode($payload), true);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud'], 'the audience is the origin only — no path, no query');
        $this->assertSame('mailto:ops@example.test', $claims['sub']);
        $this->assertSame(1_800_000_000 + 43200, $claims['exp']);
        $this->assertSame(64, strlen(Ec::base64UrlDecode($signature)), 'ES256 signatures are the fixed 64-byte r||s form');
        $this->assertSame(Ec::base64UrlEncode($point), $m[2]);
    }

    public function test_an_unconfigured_signer_reports_itself_so_the_module_falls_back_to_the_log_driver(): void
    {
        $this->assertFalse((new VapidSigner('', '', ''))->isConfigured());
    }
}
