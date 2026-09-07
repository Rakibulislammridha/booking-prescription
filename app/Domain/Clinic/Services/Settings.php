<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Services;

use App\Domain\Clinic\Support\SettingsRegistry;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Tenancy\Exceptions\TenancyNotInitialized;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Typed read/write of the tenant settings rows, cached per tenant under t:{tenantId}:settings (SCHEMA Appendix B).
 */
final class Settings
{
    private const TTL = 86400;

    public function __construct(private readonly Cache $cache) {}

    public function get(string $key): mixed
    {
        $definition = SettingsRegistry::definition($key);
        $stored = $this->stored();

        return array_key_exists($key, $stored) ? $stored[$key] : $definition['default'];
    }

    /** @return array<string, mixed> every registry key with its effective value */
    public function all(): array
    {
        return array_merge(SettingsRegistry::defaults(), $this->stored());
    }

    /** @return array<string, mixed> effective values for keys starting with $prefix ('serial.', 'queue.' …) */
    public function withPrefix(string $prefix): array
    {
        return array_intersect_key($this->all(), array_flip(SettingsRegistry::keysWithPrefix($prefix)));
    }

    public function set(string $key, mixed $value, ?User $by = null): void
    {
        $value = SettingsRegistry::validate($key, $value);

        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by_user_id' => $by?->id],
        );

        $this->forget();
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

    private function cacheKey(): string
    {
        $id = Tenancy::id() ?? throw new TenancyNotInitialized(Setting::class);

        return self::cacheKeyFor($id);
    }
}
