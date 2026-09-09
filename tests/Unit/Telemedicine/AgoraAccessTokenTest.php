<?php

declare(strict_types=1);

namespace Tests\Unit\Telemedicine;

use App\Domain\Telemedicine\Services\AgoraAccessToken;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The Agora AccessToken2 (007) codec — the sibling of `JwtTest` for the one driver whose credential is a binary
 * packing rather than a JWT. If this is wrong, every Agora token is wrong, and wrong in a way no assertion on a
 * base64 string would ever notice.
 *
 * Two independent proofs, because either alone would be circular:
 *
 *   1. ROUND TRIP — a token this codec built is decoded back into every one of its fields and each is asserted.
 *   2. INTEROP — two tokens produced by **Agora's own reference implementation** (the `agora-token` npm package,
 *      pinned to fixed `issueTs`/`salt` so they are reproducible) are decoded and signature-verified here, and
 *      the signed byte range this codec packs for the same inputs is asserted to be IDENTICAL to theirs. The
 *      base64 differs by a few characters — PHP's `gzcompress` and Node's `zlib.deflateSync` emit different but
 *      equivalent deflate encodings of the same bytes — so the comparison is over the packed message and the
 *      signature, which is what Agora actually verifies.
 */
final class AgoraAccessTokenTest extends TestCase
{
    private const APP_ID = '970CA35de60c44645bbae8a215061b33';

    private const CERTIFICATE = '5CFd2fd1755d40ecb72977518be15d3b';

    private const CHANNEL = 't9001-01jabcdefghjkmnpqrstvwxyz0';

    private const UID = '2882341273';

    private const ISSUED_AT = 1_767_225_600;

    private const SALT = 424_242;

    private const EXPIRE = 900;

    /** RTC service only, publisher privileges — `RtcTokenBuilder2.buildTokenWithUid(…, Role.PUBLISHER, 900, 900)`. */
    private const REFERENCE_RTC = '007eJxTYLh07MeT40wJeb65fTv3a28zjKrm0w94flmb0yWSI9EkU12BwdLcwNnR2DQl1cwg2cTEzMQ0KSkx1SLRyNDUwMwwydiYYWdoZgszA4NRJRsDIwMjAwsQg/hMYJIZTLKASQWGEksDA0NdA8OsxKTklNS09Iys7Ny8gsKi4pKy8orKKgMuBiMLCyNjE0Mjc2MA6uIpUA==';

    /** The same, plus the streaming service (type 3, mix + raw) — the doctor's recording-enabled token. */
    private const REFERENCE_RTC_AND_STREAMING = '007eJxTYOBpU631a256NHPF/ll7Zv0VffiT05hzXVNzer+X+aT7m+oVGCzNDZwdjU1TUs0Mkk1MzExMk5ISUy0SjQxNDcwMk4yNGXaGZrYwMzAYVbIxMDEwMrAAMYjPBCaZwSQLmFRgKLE0MDDUNTDMSkxKTklNS8/Iys7NKygsKi4pK6+orDLgYjCysDAyNjE0MjdmBpuGMIk03QAgxDrE';

    /**
     * @param  array<int, int>  $privileges
     * @return array{type: int, privileges: array<int, int>, channel: string, uid: string}
     */
    private function rtc(array $privileges): array
    {
        return ['type' => AgoraAccessToken::SERVICE_RTC, 'privileges' => $privileges, 'channel' => self::CHANNEL, 'uid' => self::UID];
    }

    /**
     * @param  array<int, int>  $privileges
     * @return array{type: int, privileges: array<int, int>, channel: string, uid: string}
     */
    private function streaming(array $privileges): array
    {
        return ['type' => AgoraAccessToken::SERVICE_STREAMING, 'privileges' => $privileges, 'channel' => self::CHANNEL, 'uid' => self::UID];
    }

    /** @return array<int, int> */
    private function publisherPrivileges(): array
    {
        return [
            AgoraAccessToken::PRIVILEGE_JOIN_CHANNEL => self::EXPIRE,
            AgoraAccessToken::PRIVILEGE_PUBLISH_AUDIO_STREAM => self::EXPIRE,
            AgoraAccessToken::PRIVILEGE_PUBLISH_VIDEO_STREAM => self::EXPIRE,
            AgoraAccessToken::PRIVILEGE_PUBLISH_DATA_STREAM => self::EXPIRE,
        ];
    }

    /** @param  array<int, array{type: int, privileges: array<int, int>, channel?: string, uid?: string}>  $services */
    private function build(array $services): string
    {
        return AgoraAccessToken::build(self::APP_ID, self::CERTIFICATE, $services, self::EXPIRE, self::ISSUED_AT, self::SALT);
    }

    public function test_a_token_decodes_back_into_every_field_it_packed(): void
    {
        $token = $this->build([$this->rtc($this->publisherPrivileges())]);

        $this->assertStringStartsWith('007', $token, 'the version prefix is three characters, not part of the base64');

        $decoded = AgoraAccessToken::parse($token);

        $this->assertNotNull($decoded);
        $this->assertSame(self::APP_ID, $decoded->appId);
        $this->assertSame(self::ISSUED_AT, $decoded->issuedAt);
        $this->assertSame(self::SALT, $decoded->salt);
        $this->assertSame(self::EXPIRE, $decoded->expireSeconds, 'expire is a DURATION from issuedAt, not a timestamp');
        $this->assertCount(1, $decoded->services);

        $rtc = $decoded->service(AgoraAccessToken::SERVICE_RTC);
        $this->assertNotNull($rtc);
        $this->assertSame(AgoraAccessToken::SERVICE_RTC, $rtc['type']);
        $this->assertSame(self::CHANNEL, $rtc['channel']);
        $this->assertSame(self::UID, $rtc['uid']);
        $this->assertSame([1 => 900, 2 => 900, 3 => 900, 4 => 900], $rtc['privileges'], 'every privilege carries its own expiry');
        $this->assertSame(32, strlen($decoded->signature), 'HMAC-SHA256 is 32 raw bytes');
        $this->assertTrue(AgoraAccessToken::verify($token, self::CERTIFICATE));
    }

    public function test_the_packing_is_little_endian_with_length_prefixed_strings(): void
    {
        $decoded = AgoraAccessToken::parse($this->build([$this->rtc([AgoraAccessToken::PRIVILEGE_JOIN_CHANNEL => self::EXPIRE])]));

        $this->assertNotNull($decoded);

        // The signed message, byte by byte: uint16 length + appId, then three uint32s, then a uint16 service count.
        $message = $decoded->message;
        $this->assertSame(pack('v', 32), substr($message, 0, 2), 'a string is a uint16 length prefix and raw bytes');
        $this->assertSame(self::APP_ID, substr($message, 2, 32));
        $this->assertSame(pack('V', self::ISSUED_AT), substr($message, 34, 4), 'uint32, little-endian');
        $this->assertSame(pack('V', self::EXPIRE), substr($message, 38, 4));
        $this->assertSame(pack('V', self::SALT), substr($message, 42, 4));
        $this->assertSame(pack('v', 1), substr($message, 46, 2), 'one service');
        $this->assertSame(pack('v', AgoraAccessToken::SERVICE_RTC), substr($message, 48, 2));
        $this->assertSame(pack('v', 1), substr($message, 50, 2), 'a map is a uint16 count …');
        $this->assertSame(pack('v', AgoraAccessToken::PRIVILEGE_JOIN_CHANNEL), substr($message, 52, 2), '… then uint16 key …');
        $this->assertSame(pack('V', self::EXPIRE), substr($message, 54, 4), '… then uint32 value');
        $this->assertSame(pack('v', strlen(self::CHANNEL)).self::CHANNEL, substr($message, 58, 2 + strlen(self::CHANNEL)));
    }

    public function test_it_agrees_byte_for_byte_with_agoras_own_reference_implementation(): void
    {
        $reference = AgoraAccessToken::parse(self::REFERENCE_RTC);
        $mine = AgoraAccessToken::parse($this->build([$this->rtc($this->publisherPrivileges())]));

        $this->assertNotNull($reference);
        $this->assertNotNull($mine);
        $this->assertSame(bin2hex($reference->message), bin2hex($mine->message), 'the signed byte range must be identical to Agora\'s');
        $this->assertSame(bin2hex($reference->signature), bin2hex($mine->signature), 'same bytes, same certificate ⇒ same HMAC');
        $this->assertTrue(AgoraAccessToken::verify(self::REFERENCE_RTC, self::CERTIFICATE), 'Agora\'s own token verifies under this codec');
    }

    public function test_a_multi_service_token_matches_the_reference_and_packs_services_in_type_order(): void
    {
        $token = $this->build([
            // Deliberately out of order: the packer sorts by service type, so grants order cannot change the bytes.
            $this->streaming([AgoraAccessToken::PRIVILEGE_PUBLISH_MIX_STREAM => self::EXPIRE, AgoraAccessToken::PRIVILEGE_PUBLISH_RAW_STREAM => self::EXPIRE]),
            $this->rtc($this->publisherPrivileges()),
        ]);

        $reference = AgoraAccessToken::parse(self::REFERENCE_RTC_AND_STREAMING);
        $mine = AgoraAccessToken::parse($token);

        $this->assertNotNull($reference);
        $this->assertNotNull($mine);
        $this->assertSame(bin2hex($reference->message), bin2hex($mine->message));
        $this->assertSame([AgoraAccessToken::SERVICE_RTC, AgoraAccessToken::SERVICE_STREAMING], array_column($mine->services, 'type'));
        $this->assertSame([1 => 900, 2 => 900], $mine->privileges(AgoraAccessToken::SERVICE_STREAMING));
        $this->assertTrue($mine->grants(AgoraAccessToken::SERVICE_STREAMING, AgoraAccessToken::PRIVILEGE_PUBLISH_MIX_STREAM));
        $this->assertTrue(AgoraAccessToken::verify($token, self::CERTIFICATE));
    }

    public function test_a_forged_privilege_map_does_not_verify_but_is_still_readable(): void
    {
        $honest = AgoraAccessToken::parse($this->build([$this->rtc([AgoraAccessToken::PRIVILEGE_JOIN_CHANNEL => self::EXPIRE])]));
        $greedy = AgoraAccessToken::parse($this->build([$this->rtc($this->publisherPrivileges())]));

        $this->assertNotNull($honest);
        $this->assertNotNull($greedy);

        // The honest token's signature over the greedy token's message: the exact attack the HMAC exists to stop.
        $forged = AgoraAccessToken::VERSION.base64_encode((string) gzcompress(
            pack('v', strlen($honest->signature)).$honest->signature.$greedy->message
        ));

        $this->assertFalse(AgoraAccessToken::verify($forged, self::CERTIFICATE));
        // …and the privileges are still READABLE without verification, which is what makes that assertion mean something.
        $this->assertSame([1 => 900, 2 => 900, 3 => 900, 4 => 900], AgoraAccessToken::parse($forged)?->privileges(AgoraAccessToken::SERVICE_RTC));
    }

    public function test_another_certificate_does_not_verify(): void
    {
        $token = $this->build([$this->rtc($this->publisherPrivileges())]);

        $this->assertFalse(AgoraAccessToken::verify($token, str_repeat('a', 32)));
        $this->assertFalse(AgoraAccessToken::verify($token, 'not-a-certificate'));
    }

    public function test_the_signing_key_is_bound_to_the_issue_time_and_the_salt(): void
    {
        $a = $this->build([$this->rtc($this->publisherPrivileges())]);
        $b = AgoraAccessToken::build(self::APP_ID, self::CERTIFICATE, [$this->rtc($this->publisherPrivileges())], self::EXPIRE, self::ISSUED_AT + 1, self::SALT);
        $c = AgoraAccessToken::build(self::APP_ID, self::CERTIFICATE, [$this->rtc($this->publisherPrivileges())], self::EXPIRE, self::ISSUED_AT, self::SALT + 1);

        $this->assertNotSame(AgoraAccessToken::parse($a)?->signature, AgoraAccessToken::parse($b)?->signature, 'a different issue time is a different signing key');
        $this->assertNotSame(AgoraAccessToken::parse($a)?->signature, AgoraAccessToken::parse($c)?->signature, 'a different salt is a different signing key');
        $this->assertTrue(AgoraAccessToken::verify($b, self::CERTIFICATE));
        $this->assertTrue(AgoraAccessToken::verify($c, self::CERTIFICATE));
    }

    public function test_garbage_is_refused_rather_than_throwing(): void
    {
        $candidates = [
            '',
            'not-a-token',
            '006'.base64_encode('whatever'),                       // the previous token version
            '007',
            '007!!!not-base64!!!',
            '007'.base64_encode('not a deflate stream'),
            AgoraAccessToken::VERSION.base64_encode((string) gzcompress(pack('v', 200).'too short')),
        ];

        foreach ($candidates as $candidate) {
            $this->assertNull(AgoraAccessToken::parse($candidate), $candidate);
            $this->assertFalse(AgoraAccessToken::verify($candidate, self::CERTIFICATE), $candidate);
        }
    }

    public function test_only_real_agora_credentials_produce_a_token(): void
    {
        $this->assertTrue(AgoraAccessToken::isCredential(self::APP_ID));
        $this->assertTrue(AgoraAccessToken::isCredential(self::CERTIFICATE));
        $this->assertFalse(AgoraAccessToken::isCredential(''));
        $this->assertFalse(AgoraAccessToken::isCredential('short'));
        $this->assertFalse(AgoraAccessToken::isCredential(str_repeat('z', 32)), 'hex only');
        $this->assertFalse(AgoraAccessToken::isCredential(self::APP_ID.'0'), '32 characters exactly');

        $this->expectException(InvalidArgumentException::class);
        AgoraAccessToken::build('not-an-app-id', self::CERTIFICATE, [$this->rtc($this->publisherPrivileges())], self::EXPIRE, self::ISSUED_AT, self::SALT);
    }

    public function test_a_token_with_no_service_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgoraAccessToken::build(self::APP_ID, self::CERTIFICATE, [], self::EXPIRE, self::ISSUED_AT, self::SALT);
    }

    public function test_the_salt_stays_inside_agoras_range(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $salt = AgoraAccessToken::salt();
            $this->assertGreaterThanOrEqual(1, $salt);
            $this->assertLessThanOrEqual(AgoraAccessToken::SALT_MAX, $salt);
        }
    }
}
