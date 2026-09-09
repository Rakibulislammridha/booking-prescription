<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Clinic\Support\SettingsRegistry;
use App\Models\Tenant\Setting;

/**
 * Delete a settings row, putting the key back to its registry default.
 *
 * Separate from `UpdateSetting` because for a `secret` key it has to be: a blank credential field means "keep what
 * is stored" (the operator did not retype it), so removal needs a signal an empty input cannot produce. The screen
 * sends the key in its own `remove` list, and the audit row records that the credential went — as `[redacted]`,
 * never the value.
 */
final class ForgetSetting
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(string $key): void
    {
        SettingsRegistry::definition($key);                                   // unknown key → UnknownSettingKey

        $row = Setting::query()->where('key', $key)->first();

        if (! $row instanceof Setting) {
            return;
        }

        $before = SettingsRegistry::isSecret($key) ? '[redacted]' : $this->settings->get($key);

        $this->settings->reset($key);

        $this->audit->record(AuditAction::Delete, $row, ['value' => $before], null, ['key' => $key]);
    }
}
