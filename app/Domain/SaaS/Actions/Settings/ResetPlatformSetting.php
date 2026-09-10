<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Settings;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\PlatformSetting;
use App\Models\Central\SuperAdmin;

/**
 * Back to the registry default ("reset to default" on a mail template, clearing a stored credential): the row
 * goes and `audit_logs_central` records `settings_change` with the value before and the default after — the
 * same trail as a write, because a reset IS a write of the default.
 */
final class ResetPlatformSetting
{
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly CentralAudit $audit,
    ) {}

    public function handle(string $key, ?SuperAdmin $by = null): mixed
    {
        $secret = PlatformSettingsRegistry::isSecret($key);
        $row = PlatformSetting::query()->where('key', $key)->first();
        $before = $this->settings->get($key);

        $this->settings->reset($key);
        $after = $this->settings->get($key);

        if ($row !== null) {
            $this->audit->record(
                CentralAuditAction::SettingsChange,
                null,
                $row,
                ['key' => $key, 'value' => $secret ? '[redacted]' : $before],
                ['key' => $key, 'value' => $secret ? '[redacted]' : $after, 'reset' => true],
                $by?->id,
            );
        }

        return $secret ? PlatformSettings::mask($after) : $after;
    }
}
