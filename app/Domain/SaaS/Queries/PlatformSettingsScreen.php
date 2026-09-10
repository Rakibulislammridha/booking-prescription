<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\PlatformSetting;

/**
 * What a console screen renders from the platform registry: every key of that screen, grouped by its first
 * segment, with its effective (masked) value, its control shape and its copy in the operator's language. The
 * Platform settings page and the Platform notifications page are both built from this rather than from a
 * hand-written list, so a key added to `PlatformSettingsRegistry` appears with no further UI work — its label,
 * description and option copy are `super.settings.<key>.*` in the language files.
 *
 * @phpstan-type SettingOption array{value: string, label: string, help: string}
 * @phpstan-type SettingRow array{key: string, type: string, value: mixed, default: mixed, options: array<int, SettingOption>|null, secret: bool, is_set: bool, requires_password: bool, multiline: bool, input: string|null, min: int|float|null, max: int|float|null, placeholders: array<int, string>, label: string, description: string, updated_at: string|null, updated_by: string|null}
 * @phpstan-type SettingGroup array{key: string, label: string, settings: array<int, SettingRow>}
 */
final class PlatformSettingsScreen
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /** @return array<int, SettingGroup> */
    public function groups(string $screen = 'settings'): array
    {
        $values = $this->settings->all();
        $rows = PlatformSetting::query()->with('updatedBy')->get()->keyBy('key');
        $groups = [];

        foreach (PlatformSettingsRegistry::all() as $key => $definition) {
            if (($definition['screen'] ?? 'settings') !== $screen) {
                continue;
            }

            $group = PlatformSettingsRegistry::groupOf($key);
            $groups[$group] ??= ['key' => $group, 'label' => (string) __('super.settings.group.'.$group), 'settings' => []];
            $groups[$group]['settings'][] = $this->row($key, $definition, $values[$key], $rows->get($key));
        }

        return array_values($groups);
    }

    /**
     * One key as a row, for a screen that places it by hand (the notifications page's identity form).
     *
     * @param  array<string, mixed>|null  $definition
     * @return SettingRow
     */
    public function row(string $key, ?array $definition = null, mixed $value = null, ?PlatformSetting $stored = null): array
    {
        $definition ??= PlatformSettingsRegistry::definition($key);
        $value ??= $this->settings->all()[$key];
        $stored ??= PlatformSetting::query()->with('updatedBy')->where('key', $key)->first();
        $secret = ($definition['secret'] ?? false) === true;

        return [
            'key' => $key,
            'type' => $definition['type'],
            'value' => $value,
            'default' => $secret ? '' : $definition['default'],
            'options' => isset($definition['options']) ? array_map(fn (string $option): array => [
                'value' => $option,
                'label' => (string) __("super.settings.{$key}.options.{$option}"),
                'help' => (string) __("super.settings.{$key}.options_help.{$option}"),
            ], $definition['options']) : null,
            'secret' => $secret,
            'is_set' => $secret ? $this->settings->hasSecret($key) : $stored instanceof PlatformSetting,
            'requires_password' => PlatformSettingsRegistry::requiresPassword($key),
            'multiline' => ($definition['multiline'] ?? false) === true,
            'input' => $definition['input'] ?? null,
            'min' => $definition['min'] ?? null,
            'max' => $definition['max'] ?? null,
            'placeholders' => $definition['placeholders'] ?? [],
            'label' => (string) __("super.settings.{$key}.label"),
            'description' => (string) __("super.settings.{$key}.description"),
            'updated_at' => $stored instanceof PlatformSetting ? $stored->updated_at?->toIso8601String() : null,
            'updated_by' => $stored instanceof PlatformSetting ? $stored->updatedBy?->name : null,
        ];
    }
}
