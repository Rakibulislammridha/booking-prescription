<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\PlatformSetting;
use App\Models\Central\SuperAdmin;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Typed read/write of `public.platform_settings` (SCHEMA §2.19), cached platform-wide under `bp:platform:settings`
 * and invalidated on every write — the control plane's counterpart of `App\Domain\Clinic\Services\Settings`.
 *
 * Read at REQUEST time. Nothing here is memoised on the instance and nothing is read at boot: a consumer asks
 * `get()` when it needs the value, which is one cache hit, so a policy flipped in the console is what the very
 * next request sees — on every Octane worker and every queue worker, with no restart. That is the whole reason
 * the super console's second-factor policy lives here and not in `config()`.
 *
 * SECRETS follow the tenant service exactly: a registry key marked `secret` is encrypted by `set()`, decrypted by
 * `get()`, MASKED by `all()` (the bulk read the screen renders), kept by a blank submit, and never handed back out
 * of the write path. No platform key is a secret yet; the contract is here so the first one cannot be mishandled.
 */
final class PlatformSettings
{
    private const TTL = 86400;

    public const CACHE_KEY = 'bp:platform:settings';

    /** How the screen shows a stored credential: enough to recognise it, not enough to use it. */
    public const MASK_PREFIX = '••••';

    public function __construct(private readonly Cache $cache) {}

    /** The effective value. A secret comes back DECRYPTED — this is the accessor module code reads. */
    public function get(string $key): mixed
    {
        $definition = PlatformSettingsRegistry::definition($key);
        $stored = $this->stored();
        $value = array_key_exists($key, $stored) ? $stored[$key] : $definition['default'];

        return ($definition['secret'] ?? false) === true ? self::decrypt($value) : $value;
    }

    /**
     * Every registry key with its effective value, secrets MASKED. This is what the Platform settings screen
     * renders, so it is deliberately the safe view.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = array_merge(PlatformSettingsRegistry::defaults(), $this->stored());

        foreach (PlatformSettingsRegistry::secretKeys() as $key) {
            $values[$key] = self::mask(self::decrypt($values[$key] ?? ''));
        }

        return $values;
    }

    /** Is a credential stored for this key? (The screen's `is_set`; no part of the value crosses the wire.) */
    public function hasSecret(string $key): bool
    {
        return PlatformSettingsRegistry::isSecret($key) && trim((string) $this->get($key)) !== '';
    }

    /** `••••1234` for a stored credential, `''` for none. */
    public static function mask(mixed $plain): string
    {
        $plain = is_string($plain) ? trim($plain) : '';

        if ($plain === '') {
            return '';
        }

        return self::MASK_PREFIX.(mb_strlen($plain) > 4 ? mb_substr($plain, -4) : '');
    }

    /**
     * Write a value. Returns the row, or null when nothing was written (a blank submit on a secret) — the caller
     * that audits the change (UpdatePlatformSetting) needs to know the difference. `$by` null is the system.
     */
    public function set(string $key, mixed $value, ?SuperAdmin $by = null): ?PlatformSetting
    {
        $value = PlatformSettingsRegistry::validate($key, $value);

        if (PlatformSettingsRegistry::isSecret($key)) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                return null;
            }

            $value = self::encrypt((string) $value);
        }

        $setting = PlatformSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by_super_admin_id' => $by?->id],
        );

        $this->forget();

        return $setting;
    }

    /** Back to the registry default: the row goes, the cache goes with it. */
    public function reset(string $key): void
    {
        PlatformSettingsRegistry::definition($key);
        PlatformSetting::query()->where('key', $key)->delete();
        $this->forget();
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> stored rows only (no defaults), keyed by setting key */
    private function stored(): array
    {
        /** @var array<string, mixed> $values */
        $values = $this->cache->remember(self::CACHE_KEY, self::TTL, function (): array {
            $rows = [];

            foreach (PlatformSetting::query()->get(['key', 'value']) as $setting) {
                if (PlatformSettingsRegistry::has($setting->key)) {
                    $rows[$setting->key] = $setting->value;
                }
            }

            return $rows;
        });

        return $values;
    }

    /** Already-encrypted input passes through untouched, so a caller that encrypts for itself is not wrapped twice. */
    private static function encrypt(string $plain): string
    {
        try {
            Crypt::decryptString($plain);

            return $plain;
        } catch (DecryptException) {
            return Crypt::encryptString($plain);
        }
    }

    /** Tolerates a plaintext value: a credential pasted straight into the row by hand still works. */
    private static function decrypt(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return trim($value);
        }
    }
}
