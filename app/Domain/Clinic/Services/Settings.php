<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Services;

use App\Domain\Clinic\Support\SettingsRegistry;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Tenancy\Exceptions\TenancyNotInitialized;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Typed read/write of the tenant settings rows, cached per tenant under t:{tenantId}:settings (SCHEMA Appendix B).
 *
 * SECRETS. A registry key marked `'secret' => true` is a credential, and this service is where that means
 * something (`SettingsRegistry` explains why each one is marked):
 *
 *   · `set()` encrypts before the value reaches `settings.value` — which is plain `jsonb`, replicated into
 *     backups and readable by anyone with a psql prompt. It is idempotent: a caller that already encrypted (the
 *     telemedicine and OCR modules do, and predate this) is detected and its ciphertext stored as-is, so the
 *     value is never double-wrapped.
 *   · `get()` decrypts, so every consumer keeps reading plaintext and nothing outside this file changed. A value
 *     that was written by hand in plain text still reads back — the alternative is a stack trace on a support
 *     call, and there is nothing to gain by refusing.
 *   · `all()` — the bulk read the settings SCREEN uses — returns a MASK instead. Two different methods with two
 *     different audiences, and the one that feeds a browser is the safe one by default.
 *   · An empty string on `set()` means "leave the stored credential alone", which is what a settings form posts
 *     when the operator did not retype it. Clearing is `null`, explicitly.
 */
final class Settings
{
    private const TTL = 86400;

    /** How the screen shows a stored credential: enough to recognise it, not enough to use it. */
    public const MASK_PREFIX = '••••';

    public function __construct(private readonly Cache $cache) {}

    /** The effective value. A secret comes back DECRYPTED — this is the accessor module code reads. */
    public function get(string $key): mixed
    {
        $definition = SettingsRegistry::definition($key);
        $stored = $this->stored();
        $value = array_key_exists($key, $stored) ? $stored[$key] : $definition['default'];

        return ($definition['secret'] ?? false) === true ? self::decrypt($value) : $value;
    }

    /**
     * Every registry key with its effective value, with secrets MASKED (`••••1234`, or `''` when unset).
     *
     * This is what the settings screen renders, so it is deliberately the masked view: a bulk read that reaches a
     * browser must not be able to leak a credential, and the caller that genuinely needs one asks `get()` for it
     * by name. `withPrefix()` inherits the same protection.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = array_merge(SettingsRegistry::defaults(), $this->stored());

        foreach (SettingsRegistry::secretKeys() as $key) {
            $values[$key] = self::mask(self::decrypt($values[$key] ?? ''));
        }

        return $values;
    }

    /** Is a credential stored for this key? (The screen's `is_set`; no part of the value crosses the wire.) */
    public function hasSecret(string $key): bool
    {
        return SettingsRegistry::isSecret($key) && trim((string) $this->get($key)) !== '';
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

    /** @return array<string, mixed> effective values for keys starting with $prefix ('serial.', 'queue.' …) */
    public function withPrefix(string $prefix): array
    {
        return array_intersect_key($this->all(), array_flip(SettingsRegistry::keysWithPrefix($prefix)));
    }

    /**
     * Write a value. Returns the row, or null when nothing was written (a blank submit on a secret) — the caller
     * that audits the change (UpdateSetting) needs to know the difference.
     */
    public function set(string $key, mixed $value, ?User $by = null): ?Setting
    {
        $value = SettingsRegistry::validate($key, $value);

        // A blank secret field means "leave the stored credential alone". `null` counts as blank: Laravel's
        // ConvertEmptyStringsToNull has already turned the empty input into one by the time a form gets here, so
        // the two cannot be told apart and must not mean opposite things. REMOVING a credential is a separate,
        // explicit signal (`ForgetSetting`, the screen's Remove button) that an empty text box cannot produce.
        if (SettingsRegistry::isSecret($key)) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                return null;
            }

            $value = self::encrypt((string) $value);
        }

        $setting = Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by_user_id' => $by?->id],
        );

        $this->forget();

        return $setting;
    }

    public function reset(string $key): void
    {
        SettingsRegistry::definition($key);
        Setting::query()->where('key', $key)->delete();
        $this->forget();
    }

    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    public static function cacheKeyFor(int $tenantId): string
    {
        return "t:{$tenantId}:settings";
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        /** @var array<string, mixed> $values */
        $values = $this->cache->remember($this->cacheKey(), self::TTL, function (): array {
            $rows = [];

            foreach (Setting::query()->get(['key', 'value']) as $setting) {
                if (SettingsRegistry::has($setting->key)) {
                    $rows[$setting->key] = $setting->value;
                }
            }

            return $rows;
        });

        return $values;
    }

    /** Already-encrypted input passes through untouched, so a module that encrypts for itself is not wrapped twice. */
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

    private function cacheKey(): string
    {
        $id = Tenancy::id() ?? throw new TenancyNotInitialized(Setting::class);

        return self::cacheKeyFor($id);
    }
}
