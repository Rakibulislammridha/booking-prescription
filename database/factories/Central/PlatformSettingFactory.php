<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\PlatformSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * platform_settings.key is unique: each call takes a registry key this process has not handed out yet, with its
 * default value. Use forKey() for a specific setting.
 *
 * @extends Factory<PlatformSetting>
 */
final class PlatformSettingFactory extends Factory
{
    protected $model = PlatformSetting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $key = $this->faker->unique()->randomElement(array_keys(PlatformSettingsRegistry::all()));

        return ['key' => $key, 'value' => PlatformSettingsRegistry::default($key)];
    }

    public function forKey(string $key, mixed $value = null): static
    {
        return $this->state(fn () => ['key' => $key, 'value' => $value ?? PlatformSettingsRegistry::default($key)]);
    }
}
