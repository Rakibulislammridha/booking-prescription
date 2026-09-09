<?php

declare(strict_types=1);

namespace Tests\Unit\SaaS;

use App\Domain\SaaS\Services\Totp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The interoperability test that matters: a hand-rolled TOTP agrees with itself no matter how wrong it is, so
 * the only proof it is right is RFC 6238's own vectors. Appendix B publishes them for an 8-digit code over the
 * ASCII seed "12345678901234567890"; our profile is 6 digits, which is the last six of the same number.
 */
final class TotpTest extends TestCase
{
    private const SEED = '12345678901234567890';

    public function test_it_matches_the_rfc_6238_test_vectors(): void
    {
        $secret = Totp::base32Encode(self::SEED);

        foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037'] as $at => $rfc) {
            $this->assertSame(substr($rfc, -6), Totp::code($secret, $at), "RFC 6238 vector at t={$at}");
        }
    }

    public function test_base32_round_trips_and_ignores_the_spaces_a_human_types(): void
    {
        $secret = Totp::generateSecret();

        $this->assertSame(32, strlen($secret), '20 random bytes are 32 base32 characters');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertSame(Totp::base32Decode($secret), Totp::base32Decode(Totp::formatSecret($secret)));
        $this->assertSame(self::SEED, Totp::base32Decode(Totp::base32Encode(self::SEED)));
    }

    public function test_verify_tolerates_one_step_of_clock_skew_and_no_more(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_760_000_000;
        $step = Totp::stepAt($now);

        $this->assertSame($step, Totp::verify($secret, Totp::code($secret, $now), $now));
        $this->assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $now - Totp::PERIOD), $now), 'a phone 30s slow');
        $this->assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $now + Totp::PERIOD), $now), 'a phone 30s fast');

        $this->assertNull(Totp::verify($secret, Totp::code($secret, $now - 2 * Totp::PERIOD), $now), '60s slow is refused');
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $now + 2 * Totp::PERIOD), $now), '60s fast is refused');
    }

    public function test_verify_refuses_rubbish_without_looking_at_the_secret(): void
    {
        $secret = Totp::generateSecret();

        $this->assertNull(Totp::verify($secret, ''));
        $this->assertNull(Totp::verify($secret, '12345'));
        $this->assertNull(Totp::verify($secret, '1234567'));
        $this->assertNull(Totp::verify($secret, 'abcdef'));
        $this->assertNull(Totp::verify('', '123456'), 'no secret, no match');
    }

    public function test_a_code_is_not_a_code_for_a_different_secret(): void
    {
        $now = 1_760_000_000;

        $this->assertNull(Totp::verify(Totp::generateSecret(), Totp::code(Totp::generateSecret(), $now), $now));
    }

    public function test_the_provisioning_uri_is_the_google_authenticator_key_format(): void
    {
        $uri = Totp::provisioningUri('JBSWY3DPEHPK3PXP', 'root@bp.test', 'Booking to Prescription');

        $this->assertStringStartsWith('otpauth://totp/Booking%20to%20Prescription%3Aroot%40bp.test?', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Booking%20to%20Prescription', $uri);
        $this->assertStringContainsString('algorithm=SHA1', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    public function test_an_empty_secret_cannot_produce_a_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Totp::codeForStep('!!!!', 1);
    }
}
