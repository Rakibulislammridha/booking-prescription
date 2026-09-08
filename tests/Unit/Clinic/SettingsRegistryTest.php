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

        $this->assertCount(31, $keys);          // + the six telemedicine.* keys (Module K, BRIEF §5.K)
        $this->assertSame(60, SettingsRegistry::default('serial.cancel_cutoff_minutes'));
        $this->assertSame('both', SettingsRegistry::default('queue.display_voice'));
        $this->assertSame(120, SettingsRegistry::default('security.session_timeout_minutes'));

        // Both defaults are OFF: a clinic opts in to holding messages overnight and to taking money online.
        $this->assertFalse(SettingsRegistry::default('notifications.quiet_hours_enabled'));
        $this->assertSame('21:00', SettingsRegistry::default('notifications.quiet_hours_start'));
        $this->assertSame('08:00', SettingsRegistry::default('notifications.quiet_hours_end'));
        $this->assertFalse(SettingsRegistry::default('booking.online_payment_enabled'));

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^(queue|serial|kiosk|reception|booking|billing|notifications|security|telemedicine)\.[a-z_]+$/', $key);
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
