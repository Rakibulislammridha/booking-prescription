<?php

declare(strict_types=1);

namespace Tests\Unit\Clinic;

use App\Domain\Clinic\Exceptions\InvalidSettingValue;
use App\Domain\Clinic\Exceptions\UnknownSettingKey;
use App\Domain\Clinic\Support\SettingsRegistry;
use PHPUnit\Framework\TestCase;

final class SettingsRegistryTest extends TestCase
{
    public function test_registry_matches_appendix_b(): void
    {
        $keys = array_keys(SettingsRegistry::all());

        $this->assertCount(35, $keys);          // + the six telemedicine.* keys (Module K, BRIEF §5.K),
        // booking.advance_payment_hold_minutes (§5.C advance payment), the two patients.ocr_* keys (§5.H) and
        // booking.self_service_daily_limit (the per-mobile cap that stands in for the OTP)
        $this->assertSame(60, SettingsRegistry::default('serial.cancel_cutoff_minutes'));
        $this->assertSame('both', SettingsRegistry::default('queue.display_voice'));
        $this->assertSame(120, SettingsRegistry::default('security.session_timeout_minutes'));

        // Both defaults are OFF: a clinic opts in to holding messages overnight and to taking money online.
        $this->assertFalse(SettingsRegistry::default('notifications.quiet_hours_enabled'));
        $this->assertSame('21:00', SettingsRegistry::default('notifications.quiet_hours_start'));
        $this->assertSame('08:00', SettingsRegistry::default('notifications.quiet_hours_end'));
        $this->assertFalse(SettingsRegistry::default('booking.online_payment_enabled'));

        // BRIEF §5.C: mobile → patient → session → serial, no code step. The OTP is opt-in per clinic; what guards
        // the open form by default is a modest per-mobile daily cap, and 0 is the documented way to lift it.
        $this->assertFalse(SettingsRegistry::default('kiosk.otp_required'));
        $this->assertSame(3, SettingsRegistry::default('booking.self_service_daily_limit'));
        $this->assertSame(0, SettingsRegistry::validate('booking.self_service_daily_limit', '0'));

        try {
            SettingsRegistry::validate('booking.self_service_daily_limit', 51);
            $this->fail('a cap above 50 is not a modest cap');
        } catch (InvalidSettingValue) {
            $this->addToAssertionCount(1);
        }

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^(queue|serial|kiosk|reception|booking|billing|notifications|patients|security|telemedicine)\.[a-z_]+$/', $key);
        }
    }

    /**
     * The `secret` flag is a storage contract, not a UI hint (SettingsRegistry's docblock): it decides whether the
     * value is encrypted at rest, masked on the wire and redacted in audit rows. A credential added without it
     * would render as a plain text input and land in `settings.value` in clear text, which is the bug this closes.
     */
    public function test_every_credential_carries_the_secret_flag_and_nothing_else_does(): void
    {
        $this->assertSame(['telemedicine.api_key', 'telemedicine.api_secret', 'patients.ocr_api_key'], SettingsRegistry::secretKeys());

        foreach (SettingsRegistry::secretKeys() as $key) {
            $this->assertTrue(SettingsRegistry::isSecret($key));
            $this->assertSame('string', SettingsRegistry::definition($key)['type'], 'a credential is free text, never an option list');
            $this->assertSame('', SettingsRegistry::default($key), 'a credential has no default worth shipping');
        }

        // A blank credential is "unchanged", not a type error — it is what an untouched password field posts.
        $this->assertNull(SettingsRegistry::validate('telemedicine.api_secret', null));
        $this->assertSame('', SettingsRegistry::validate('telemedicine.api_secret', ''));

        foreach (['telemedicine.host', 'telemedicine.provider', 'queue.display_voice', 'security.session_timeout_minutes'] as $key) {
            $this->assertFalse(SettingsRegistry::isSecret($key), "{$key} is not a credential");
        }
    }

    public function test_validate_casts_and_bounds(): void
    {
        $this->assertSame(7, SettingsRegistry::validate('queue.auto_noshow_after', '7'));
        $this->assertTrue(SettingsRegistry::validate('serial.vip_enabled', 'true'));
        $this->assertFalse(SettingsRegistry::validate('serial.vip_enabled', 0));
        $this->assertSame(0.5, SettingsRegistry::validate('serial.expected_show_rate', '0.5'));
        $this->assertSame('bn', SettingsRegistry::validate('queue.display_voice', 'bn'));
    }

    public function test_validate_rejects_bad_input(): void
    {
        $this->assertThrowsDomain(fn () => SettingsRegistry::validate('serial.default_block_size', 0), InvalidSettingValue::class);
        $this->assertThrowsDomain(fn () => SettingsRegistry::validate('serial.default_block_size', 'ten'), InvalidSettingValue::class);
        $this->assertThrowsDomain(fn () => SettingsRegistry::validate('queue.display_voice', 'loud'), InvalidSettingValue::class);
        $this->assertSame('23:30', SettingsRegistry::validate('notifications.quiet_hours_start', '23:30'));
        $this->assertThrowsDomain(fn () => SettingsRegistry::validate('notifications.quiet_hours_start', '9pm'), InvalidSettingValue::class);
        $this->assertThrowsDomain(fn () => SettingsRegistry::validate('notifications.quiet_hours_end', '24:00'), InvalidSettingValue::class);
        $this->assertThrowsDomain(fn () => SettingsRegistry::validate('made.up', 1), UnknownSettingKey::class);
    }

    /** @param  class-string<\Throwable>  $class */
    private function assertThrowsDomain(callable $fn, string $class): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);

            return;
        }

        $this->fail("Expected {$class}");
    }
}
