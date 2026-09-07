<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Clinic\Support\SettingsRegistry;
use App\Models\Tenant\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * settings.key is unique per tenant: each call takes the next registry key (with its default value) that this
 * process has not handed out yet, so repeated create() calls never collide. Use forKey() for a specific setting.
 *
 * @extends Factory<Setting>
 */
final class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $key = $this->faker->unique()->randomElement(array_keys(SettingsRegistry::all()));

        return ['key' => $key, 'value' => SettingsRegistry::default($key)];
    }

    public function forKey(string $key, mixed $value = null): static
    {
        return $this->state(fn () => ['key' => $key, 'value' => $value ?? SettingsRegistry::default($key)]);
    }
}
