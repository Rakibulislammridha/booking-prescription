<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Support;

use App\Domain\SaaS\Enums\SuperTwoFactorPolicy;
use App\Domain\SaaS\Exceptions\InvalidPlatformSettingValue;
use App\Domain\SaaS\Exceptions\UnknownPlatformSettingKey;

/**
 * The closed registry of PLATFORM settings keys — `public.platform_settings` (SCHEMA §2.19), the control plane's
 * counterpart of the tenant registry (`App\Domain\Clinic\Support\SettingsRegistry`, SCHEMA Appendix B). One row
 * per key, a missing row means the default, and the default may be derived from config so that a deploy-time
 * env value seeds the console toggle rather than competing with it.
 *
 * The same storage contracts as the tenant registry, read by the same downstream code paths:
 *
 *   · `secret` — a credential: `PlatformSettings::set()` encrypts it, `all()` masks it, a blank submit keeps it,
 *     and the audit row says `[redacted]`. No platform key is a secret today; the contract exists so the first
 *     one is handled correctly by the service and the screen rather than by whoever adds it.
 *   · `reauth` — changing the key re-asks the operator's CURRENT PASSWORD (not a TOTP code: the first such key
 *     is the switch that turns the second factor off, and a switch that needs the thing it disables is not a
 *     switch). Every `security.*` key should carry it.
 *
 * The console's Platform settings screen is rendered from this list, so a key registered here appears there with
 * no further UI work; its label, description and option copy live under `super.settings.<key>.*` in both
 * language files (a test asserts they exist).
 */
final class PlatformSettingsRegistry
{
    public const SUPER_TWO_FACTOR = 'security.super_two_factor';

    /**
     * @return array<string, array{type: string, default: mixed, options?: array<int, string>, pattern?: string, min?: int|float, max?: int|float, secret?: bool, reauth?: bool}>
     */
    public static function all(): array
    {
        return [
            // The super console's second-factor policy (ARCHITECTURE §6.5, SuperTwoFactorPolicy). The default
            // is seeded from `saas.two_factor.required` (env SUPER_2FA_REQUIRED) — `true` is `required`, the
            // production default; `false` is `optional`, the old meaning of the env flag — so an existing
            // deployment keeps its behaviour until an operator chooses otherwise in the console.
            self::SUPER_TWO_FACTOR => [
                'type' => 'string',
                'default' => config('saas.two_factor.required', true) ? SuperTwoFactorPolicy::Required->value : SuperTwoFactorPolicy::Optional->value,
                'options' => SuperTwoFactorPolicy::values(),
                'reauth' => true,
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** A credential: encrypted at rest, masked on the wire, redacted in audit rows. */
    public static function isSecret(string $key): bool
    {
        return (self::all()[$key]['secret'] ?? false) === true;
    }

    /** Changing this key re-asks the operator's current password. */
    public static function requiresPassword(string $key): bool
    {
        return (self::all()[$key]['reauth'] ?? false) === true;
    }

    /** @return array<int, string> every secret key, for a sweep or an assertion */
    public static function secretKeys(): array
    {
        return array_keys(array_filter(self::all(), fn (array $d) => ($d['secret'] ?? false) === true));
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

    /** The first dotted segment (`security`), which is how the screen groups keys. */
    public static function groupOf(string $key): string
    {
        return explode('.', $key, 2)[0];
    }

    /**
     * @return array{type: string, default: mixed, options?: array<int, string>, pattern?: string, min?: int|float, max?: int|float, secret?: bool, reauth?: bool}
     */
    public static function definition(string $key): array
    {
        return self::all()[$key] ?? throw new UnknownPlatformSettingKey($key);
    }

    /** Type-checks and normalises a value for the key; throws InvalidPlatformSettingValue. */
    public static function validate(string $key, mixed $value): mixed
    {
        $def = self::definition($key);

        // A blank credential field is "unchanged", not a type error (ConvertEmptyStringsToNull makes it null).
        if ($value === null && ($def['secret'] ?? false) === true) {
            return null;
        }

        $ok = match ($def['type']) {
            'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
            'string' => is_string($value),
            default => false,
        };

        if (! $ok) {
            throw new InvalidPlatformSettingValue($key, "expected {$def['type']}");
        }

        $value = match ($def['type']) {
            'int' => (int) $value,
            'number' => is_string($value) ? (float) $value : $value,
            'bool' => is_bool($value) ? $value : in_array($value, [1, '1', 'true'], true),
            default => $value,
        };

        if (isset($def['options']) && ! in_array($value, $def['options'], true)) {
            throw new InvalidPlatformSettingValue($key, 'must be one of '.implode(', ', $def['options']));
        }

        if (isset($def['pattern']) && (! is_string($value) || preg_match($def['pattern'], $value) !== 1)) {
            throw new InvalidPlatformSettingValue($key, 'must match '.$def['pattern']);
        }

        if (isset($def['min']) && $value < $def['min']) {
            throw new InvalidPlatformSettingValue($key, "must be >= {$def['min']}");
        }

        if (isset($def['max']) && $value > $def['max']) {
            throw new InvalidPlatformSettingValue($key, "must be <= {$def['max']}");
        }

        return $value;
    }
}
