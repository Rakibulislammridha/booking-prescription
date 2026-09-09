<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\PlatformSetting;

/**
 * What the console's Platform settings screen renders: every registry key, grouped by its first segment, with
 * its effective (masked) value, its control shape and its copy in the operator's language. The screen is built
 * from this rather than from a hand-written list, so a key added to `PlatformSettingsRegistry` appears with no
 * further UI work — its label, description and option copy are `super.settings.<key>.*` in the language files.
 *
 * @phpstan-type SettingOption array{value: string, label: string, help: string}
 * @phpstan-type SettingRow array{key: string, type: string, value: mixed, default: mixed, options: array<int, SettingOption>|null, secret: bool, is_set: bool, requires_password: bool, label: string, description: string, updated_at: string|null, updated_by: string|null}
 * @phpstan-type SettingGroup array{key: string, label: string, settings: array<int, SettingRow>}
 */
final class PlatformSettingsScreen
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /** @return array<int, SettingGroup> */
    public function groups(): array
    {
        $values = $this->settings->all();
        $rows = PlatformSetting::query()->with('updatedBy')->get()->keyBy('key');
        $groups = [];

        foreach (PlatformSettingsRegistry::all() as $key => $definition) {
            $group = PlatformSettingsRegistry::groupOf($key);
            $groups[$group] ??= ['key' => $group, 'label' => (string) __('super.settings.group.'.$group), 'settings' => []];

            $row = $rows->get($key);
            $secret = ($definition['secret'] ?? false) === true;

            $groups[$group]['settings'][] = [
                'key' => $key,
                'type' => $definition['type'],
                'value' => $values[$key],
                'default' => $secret ? '' : $definition['default'],
                'options' => isset($definition['options']) ? array_map(fn (string $option): array => [
                    'value' => $option,
                    'label' => (string) __("super.settings.{$key}.options.{$option}"),
                    'help' => (string) __("super.settings.{$key}.options_help.{$option}"),
                ], $definition['options']) : null,
                'secret' => $secret,
                'is_set' => $secret ? $this->settings->hasSecret($key) : $row instanceof PlatformSetting,
                'requires_password' => PlatformSettingsRegistry::requiresPassword($key),
                'label' => (string) __("super.settings.{$key}.label"),
                'description' => (string) __("super.settings.{$key}.description"),
                'updated_at' => $row instanceof PlatformSetting ? $row->updated_at?->toIso8601String() : null,
                'updated_by' => $row instanceof PlatformSetting ? $row->updatedBy?->name : null,
            ];
        }

        return array_values($groups);
    }
}
