<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Services;

use App\Domain\Telemedicine\Data\AgoraToken;
use InvalidArgumentException;

/**
 * Agora **AccessToken2**, version `007` — the reason this driver could not reuse `Jwt`.
 *
 * LiveKit and Jitsi both want an HS256 JWT, so thirty lines of `hash_hmac` + base64url served both. Agora's
 * credential is a *packed binary structure*: little-endian primitives, deflate-compressed, base64'd behind a
 * three-character version prefix. None of that fits a JWT helper, which is exactly why the module shipped
 * without Agora — and it is all this file is.
 *
 * ## The byte layout (little-endian throughout)
 *
 *     uint16(v)  2 bytes                       pack('v')
 *     uint32(V)  4 bytes                       pack('V')
 *     string     uint16 length + raw bytes     NOT null-terminated, NOT utf16
 *     map        uint16 count + count × (uint16 key + uint32 value), keys ASCENDING
 *
 * A service is `uint16 type + privilege map`, and the RTC service (type 1) appends `string channel + string uid`.
 * The signed message is:
 *
 *     string(appId) uint32(issuedAt) uint32(expireSeconds) uint32(salt) uint16(serviceCount) service…
 *
 * `expireSeconds` and every privilege expiry are DURATIONS measured from `issuedAt`, not absolute timestamps —
 * the single easiest thing to get wrong, and the reason `parse()` exists.
 *
 * The signing key is derived in two HMAC steps so that neither the issue time nor the salt can be moved without
 * invalidating the signature:
 *
 *     signing   = HMAC-SHA256(key = uint32(issuedAt), message = appCertificate)
 *     signing   = HMAC-SHA256(key = uint32(salt),     message = signing)
 *     signature = HMAC-SHA256(key = signing,          message = the packed message above)
 *
 * and the wire form is `'007' . base64(deflate(string(signature) . message))`.
 *
 * `parse()`/`verify()` are the `Jwt::decode()` of this file: production only ever builds, but a token nobody can
 * decode is a token nobody can trust, so the codec round-trips itself and the suite asserts every field.
 */
final class AgoraAccessToken
{
    public const VERSION = '007';

    /** Service ids (AccessToken2 `kServices`). Only these two are ever minted here. */
    public const SERVICE_RTC = 1;

    /** Media push / CDN publishing — Agora's nearest equivalent of LiveKit's `roomRecord`. */
    public const SERVICE_STREAMING = 3;

    public const PRIVILEGE_JOIN_CHANNEL = 1;

    public const PRIVILEGE_PUBLISH_AUDIO_STREAM = 2;

    public const PRIVILEGE_PUBLISH_VIDEO_STREAM = 3;

    public const PRIVILEGE_PUBLISH_DATA_STREAM = 4;

    public const PRIVILEGE_PUBLISH_MIX_STREAM = 1;

    public const PRIVILEGE_PUBLISH_RAW_STREAM = 2;

    /** Agora's salt is drawn from (1, 99999999); staying inside it keeps the uint32 field byte-identical. */
    public const SALT_MAX = 99_999_999;

    /**
     * An App ID and an App Certificate are both 32 hexadecimal characters. Agora's own builders refuse to
     * produce a token for anything else, and this is what makes `AgoraProvider::isConfigured()` honest: a clinic
     * with a half-pasted certificate falls back to the null driver instead of minting a token Agora will reject.
     */
    public static function isCredential(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F]{32}$/', $value) === 1;
    }

    /**
     * @param  array<int, array{type: int, privileges: array<int, int>, channel?: string, uid?: string}>  $services
     *
     * @throws InvalidArgumentException when the credentials are not Agora credentials, or nothing was granted
     */
    public static function build(
        string $appId,
        string $appCertificate,
        array $services,
        int $expireSeconds,
        int $issuedAt,
        int $salt,
    ): string {
        if (! self::isCredential($appId) || ! self::isCredential($appCertificate)) {
            throw new InvalidArgumentException('agora: the App ID and App Certificate must each be 32 hex characters');
        }

        if ($services === []) {
            throw new InvalidArgumentException('agora: a token with no service grants nothing');
        }

        // Services are packed in ascending type order, so two tokens with the same grants are the same bytes.
        usort($services, fn (array $a, array $b): int => $a['type'] <=> $b['type']);

        $message = self::string($appId)
            .self::uint32($issuedAt)
            .self::uint32($expireSeconds)
            .self::uint32($salt)
            .self::uint16(count($services));

        foreach ($services as $service) {
            $message .= self::packService($service);
        }

        $signature = hash_hmac('sha256', $message, self::signingKey($appCertificate, $issuedAt, $salt), true);

        return self::VERSION.base64_encode((string) gzcompress(self::string($signature).$message));
    }

    /**
     * The inverse of `build()`. Returns null for anything that is not a well-formed 007 token — a wrong version,
     * a truncated buffer, base64 that is not base64 — so a caller never inspects a half-read structure.
     */
    public static function parse(string $token): ?AgoraToken
    {
        if (! str_starts_with($token, self::VERSION)) {
            return null;
        }

        $compressed = base64_decode(substr($token, strlen(self::VERSION)), true);

        if ($compressed === false) {
            return null;
        }

        $raw = self::inflate($compressed);

        if ($raw === null) {
            return null;
        }

        $offset = 0;

        try {
            $signature = self::readString($raw, $offset);
            $message = substr($raw, $offset);
            $appId = self::readString($raw, $offset);
            $issuedAt = self::readUint32($raw, $offset);
            $expire = self::readUint32($raw, $offset);
            $salt = self::readUint32($raw, $offset);
            $count = self::readUint16($raw, $offset);

            $services = [];

            for ($i = 0; $i < $count; $i++) {
                $services[] = self::readService($raw, $offset);
            }
        } catch (InvalidArgumentException) {
            return null;                       // a truncated token is not a token
        }

        return new AgoraToken($appId, $issuedAt, $expire, $salt, $services, $signature, $message);
    }

    /** Re-derives the signing key from the DECODED issue time and salt and compares in constant time. */
    public static function verify(string $token, string $appCertificate): bool
    {
        $decoded = self::parse($token);

        if ($decoded === null || ! self::isCredential($appCertificate)) {
            return false;
        }

        $expected = hash_hmac('sha256', $decoded->message, self::signingKey($appCertificate, $decoded->issuedAt, $decoded->salt), true);

        return hash_equals($expected, $decoded->signature);
    }

    /** A fresh salt for one token. Agora's own builders use (1, 99999999); nothing depends on it but uniqueness. */
    public static function salt(): int
    {
        return random_int(1, self::SALT_MAX);
    }

    /**
     * `gzuncompress()` answers `false` for a payload that is not a zlib stream AND emits a PHP warning while doing
     * it. Garbage in is an ordinary, expected case here — `parse()` is fed whatever arrives — so the warning is
     * swallowed deliberately rather than with `@`, which PHPUnit's error handler promotes to a test warning.
     */
    private static function inflate(string $compressed): ?string
    {
        set_error_handler(static fn (): bool => true);

        try {
            $raw = gzuncompress($compressed);
        } finally {
            restore_error_handler();
        }

        return $raw === false ? null : $raw;
    }

    private static function signingKey(string $appCertificate, int $issuedAt, int $salt): string
    {
        $signing = hash_hmac('sha256', $appCertificate, self::uint32($issuedAt), true);

        return hash_hmac('sha256', $signing, self::uint32($salt), true);
    }

    /** @param  array{type: int, privileges: array<int, int>, channel?: string, uid?: string}  $service */
    private static function packService(array $service): string
    {
        $packed = self::uint16($service['type']).self::mapUint32($service['privileges']);

        // Only the channel-bound services carry a channel and a uid; the streaming service is packed the same way.
        if ($service['type'] === self::SERVICE_RTC || $service['type'] === self::SERVICE_STREAMING) {
            $packed .= self::string($service['channel'] ?? '').self::string($service['uid'] ?? '');
        }

        return $packed;
    }

    /** @return array{type: int, privileges: array<int, int>, channel: string, uid: string} */
    private static function readService(string $raw, int &$offset): array
    {
        $type = self::readUint16($raw, $offset);
        $privileges = [];
        $count = self::readUint16($raw, $offset);

        for ($i = 0; $i < $count; $i++) {
            $privilege = self::readUint16($raw, $offset);
            $privileges[$privilege] = self::readUint32($raw, $offset);
        }

        $channel = '';
        $uid = '';

        if ($type === self::SERVICE_RTC || $type === self::SERVICE_STREAMING) {
            $channel = self::readString($raw, $offset);
            $uid = self::readString($raw, $offset);
        }

        return ['type' => $type, 'privileges' => $privileges, 'channel' => $channel, 'uid' => $uid];
    }

    private static function uint16(int $value): string
    {
        return pack('v', $value);
    }

    private static function uint32(int $value): string
    {
        return pack('V', $value);
    }

    private static function string(string $value): string
    {
        return self::uint16(strlen($value)).$value;
    }

    /** @param  array<int, int>  $map */
    private static function mapUint32(array $map): string
    {
        ksort($map, SORT_NUMERIC);
        $packed = self::uint16(count($map));

        foreach ($map as $key => $value) {
            $packed .= self::uint16($key).self::uint32($value);
        }

        return $packed;
    }

    private static function readUint16(string $raw, int &$offset): int
    {
        return (int) self::read($raw, $offset, 2, 'v');
    }

    private static function readUint32(string $raw, int &$offset): int
    {
        return (int) self::read($raw, $offset, 4, 'V');
    }

    private static function readString(string $raw, int &$offset): string
    {
        $length = self::readUint16($raw, $offset);

        if ($offset + $length > strlen($raw)) {
            throw new InvalidArgumentException('agora: truncated string');
        }

        $value = substr($raw, $offset, $length);
        $offset += $length;

        return $value;
    }

    private static function read(string $raw, int &$offset, int $width, string $format): int
    {
        if ($offset + $width > strlen($raw)) {
            throw new InvalidArgumentException('agora: truncated buffer');
        }

        /** @var array{1?: int} $unpacked */
        $unpacked = unpack($format, substr($raw, $offset, $width));
        $offset += $width;

        return $unpacked[1] ?? throw new InvalidArgumentException('agora: unreadable number');
    }
}
