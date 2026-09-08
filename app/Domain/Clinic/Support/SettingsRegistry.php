<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Support;

use App\Domain\Clinic\Exceptions\InvalidSettingValue;
use App\Domain\Clinic\Exceptions\UnknownSettingKey;

/**
 * The closed registry of tenant settings keys (SCHEMA.md Appendix B). A missing row means the default.
 */
final class SettingsRegistry
{
    /**
     * @return array<string, array{type: string, default: mixed, options?: array<int, string>, pattern?: string, min?: int|float, max?: int|float}>
     */
    public static function all(): array
    {
        return [
            'queue.auto_noshow_after' => ['type' => 'int', 'default' => 3, 'min' => 0],
            'queue.auto_noshow_grace_minutes' => ['type' => 'int', 'default' => 10, 'min' => 0],
            'queue.notify_ahead' => ['type' => 'int', 'default' => 3, 'min' => 0],
            'queue.delay_notify_min_change' => ['type' => 'int', 'default' => 10, 'min' => 0],
            'queue.display_voice' => ['type' => 'string', 'default' => 'both', 'options' => ['both', 'bn', 'en', 'off']],
            'queue.public_page_enabled' => ['type' => 'bool', 'default' => true],
            'serial.default_block_size' => ['type' => 'int', 'default' => 5, 'min' => 1, 'max' => 30],
            'serial.max_active_blocks_per_device' => ['type' => 'int', 'default' => 2, 'min' => 1],
            'serial.block_topup_threshold' => ['type' => 'int', 'default' => 3, 'min' => 0],
            'serial.elderly_skip' => ['type' => 'int', 'default' => 2, 'min' => 0],
            'serial.vip_enabled' => ['type' => 'bool', 'default' => true],
            'serial.expected_show_rate' => ['type' => 'number', 'default' => 0.8, 'min' => 0, 'max' => 1],
            'serial.receptionist_extension_limit' => ['type' => 'int', 'default' => 0, 'min' => 0],
            'serial.cancel_cutoff_minutes' => ['type' => 'int', 'default' => 60, 'min' => 0],
            'kiosk.otp_required' => ['type' => 'bool', 'default' => true],
            'kiosk.self_checkin_enabled' => ['type' => 'bool', 'default' => false],
            'reception.pin_idle_minutes' => ['type' => 'int', 'default' => 15, 'min' => 1],
            'reception.sound_on_offline' => ['type' => 'bool', 'default' => true],
            'booking.online_payment_enabled' => ['type' => 'bool', 'default' => false],
            'billing.vat_percent' => ['type' => 'number', 'default' => 0, 'min' => 0, 'max' => 100],
            'billing.discount_approval_threshold_paisa' => ['type' => 'int', 'default' => 50000, 'min' => 0],
            // Clinic-local hours during which non-urgent messages are held until morning (BRIEF §5.J: "respect
            // quiet hours if settings define them" — so off until a clinic turns them on). `HH:MM`, 24-hour; a
            // window that wraps past midnight is normal and handled by App\Domain\Notifications\Services\QuietHours.
            'notifications.quiet_hours_enabled' => ['type' => 'bool', 'default' => false],
            'notifications.quiet_hours_start' => ['type' => 'string', 'default' => '21:00', 'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/'],
            'notifications.quiet_hours_end' => ['type' => 'string', 'default' => '08:00', 'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/'],
            'security.session_timeout_minutes' => ['type' => 'int', 'default' => 120, 'min' => 5],
            // Telemedicine (BRIEF §5.K). `provider` = 'default' follows config('telemedicine.default'); the rest
            // override config/telemedicine.php per clinic. `api_secret` holds a Laravel-ENCRYPTED string written
            // by App\Domain\Telemedicine\Services\TelemedicineSettings::storeSecret() — `settings.value` is plain
            // jsonb and a video API secret does not belong there in clear text.
            'telemedicine.provider' => ['type' => 'string', 'default' => 'default', 'options' => ['default', 'livekit', 'jitsi', 'null']],
            'telemedicine.host' => ['type' => 'string', 'default' => ''],
            'telemedicine.api_key' => ['type' => 'string', 'default' => ''],
            'telemedicine.api_secret' => ['type' => 'string', 'default' => ''],
            'telemedicine.recording_enabled' => ['type' => 'bool', 'default' => false],
            'telemedicine.max_minutes' => ['type' => 'int', 'default' => 45, 'min' => 5, 'max' => 240],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_map(fn (array $def) => $def['default'], self::all());
    }

    public static function default(string $key): mixed
    {
        return self::definition($key)['default'];
    }

    /** @return array<int, string> keys sharing a prefix such as 'serial.' */
    public static function keysWithPrefix(string $prefix): array
    {
        return array_values(array_filter(array_keys(self::all()), fn (string $k) => str_starts_with($k, $prefix)));
    }

    /**
     * @return array{type: string, default: mixed, options?: array<int, string>, pattern?: string, min?: int|float, max?: int|float}
     */
    public static function definition(string $key): array
    {
        return self::all()[$key] ?? throw new UnknownSettingKey($key);
    }

    /** Type-checks and normalises a value for the key; throws InvalidSettingValue. */
    public static function validate(string $key, mixed $value): mixed
    {
        $def = self::definition($key);

        $ok = match ($def['type']) {
            'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
            'string' => is_string($value),
            default => false,
        };

        if (! $ok) {
            throw new InvalidSettingValue($key, "expected {$def['type']}");
        }

        $value = match ($def['type']) {
            'int' => (int) $value,
            'number' => is_string($value) ? (float) $value : $value,
            'bool' => is_bool($value) ? $value : in_array($value, [1, '1', 'true'], true),
            default => $value,
        };

        if (isset($def['options']) && ! in_array($value, $def['options'], true)) {
            throw new InvalidSettingValue($key, 'must be one of '.implode(', ', $def['options']));
        }

        // A free-text key with a shape (`21:00`): "9pm" would otherwise parse to 09:00 and silently invert a
        // clinic's quiet window rather than being refused.
        if (isset($def['pattern']) && (! is_string($value) || preg_match($def['pattern'], $value) !== 1)) {
            throw new InvalidSettingValue($key, 'must match '.$def['pattern']);
        }

        if (isset($def['min']) && $value < $def['min']) {
            throw new InvalidSettingValue($key, "must be >= {$def['min']}");
        }

        if (isset($def['max']) && $value > $def['max']) {
            throw new InvalidSettingValue($key, "must be <= {$def['max']}");
        }

        return $value;
    }
}
