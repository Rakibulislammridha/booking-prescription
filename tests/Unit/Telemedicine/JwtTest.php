<?php

declare(strict_types=1);

namespace Tests\Unit\Telemedicine;

use App\Domain\Telemedicine\Services\Jwt;
use PHPUnit\Framework\TestCase;

/** The 30 lines of HS256 both self-hosted drivers depend on. If this is wrong, every token is wrong. */
final class JwtTest extends TestCase
{
    private const SECRET = 'a-very-secret-value';

    public function test_a_token_round_trips_its_claims(): void
    {
        $token = Jwt::encode(['iss' => 'key-1', 'sub' => 'doctor-abc', 'exp' => time() + 60], self::SECRET);

        $claims = Jwt::decode($token, self::SECRET);

        $this->assertIsArray($claims);
        $this->assertSame('key-1', $claims['iss']);
        $this->assertSame('doctor-abc', $claims['sub']);
    }

    public function test_the_header_is_hs256_and_the_parts_are_base64url(): void
    {
        $token = Jwt::encode(['sub' => 'x'], self::SECRET);
        [$header, $payload, $signature] = explode('.', $token);

        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], json_decode((string) Jwt::base64UrlDecode($header), true));
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $header.$payload.$signature, 'base64url has no +, / or padding');
    }

    public function test_a_tampered_payload_does_not_verify(): void
    {
        $token = Jwt::encode(['sub' => 'patient-1', 'video' => ['roomAdmin' => false]], self::SECRET);
        [$header, , $signature] = explode('.', $token);
        $forged = $header.'.'.Jwt::base64UrlEncode((string) json_encode(['sub' => 'patient-1', 'video' => ['roomAdmin' => true]])).'.'.$signature;

        $this->assertNull(Jwt::decode($forged, self::SECRET));
        // …but the claims are still READABLE without verification, which is what makes the assertion above meaningful.
        $this->assertSame(true, Jwt::claimsOf($forged)['video']['roomAdmin']);
    }

    public function test_another_secret_does_not_verify(): void
    {
        $this->assertNull(Jwt::decode(Jwt::encode(['sub' => 'x'], self::SECRET), 'a-different-secret'));
    }

    public function test_an_expired_token_is_refused_and_leeway_is_honoured(): void
    {
        $token = Jwt::encode(['sub' => 'x', 'exp' => time() - 10], self::SECRET);

        $this->assertNull(Jwt::decode($token, self::SECRET));
        $this->assertIsArray(Jwt::decode($token, self::SECRET, leewaySeconds: 60));
    }

    public function test_a_not_yet_valid_token_is_refused(): void
    {
        $this->assertNull(Jwt::decode(Jwt::encode(['sub' => 'x', 'nbf' => time() + 300], self::SECRET), self::SECRET));
    }

    public function test_garbage_is_refused_rather_than_throwing(): void
    {
        foreach (['', 'not-a-token', 'a.b', 'a.b.c.d', 'aaa.bbb.ccc'] as $candidate) {
            $this->assertNull(Jwt::decode($candidate, self::SECRET), $candidate);
        }
    }
}
