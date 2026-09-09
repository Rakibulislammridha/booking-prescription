<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Settings;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\SuperAdmin;

/**
 * Write one platform settings key and leave a trail in `public.audit_logs_central` (`settings_change`, SCHEMA
 * §2.13) with the value before and after. `$by` null is the system — a console command or a seeder — and the
 * audit row says so (`super_admin_id` NULL), which is exactly what §2.13 wants for a change nobody clicked.
 *
 * A `secret` key's row carries `[redacted]` on both sides, never the value and never the ciphertext, for the same
 * reason the tenant `UpdateSetting` does: the audit log is read by more people, kept longer and exported.
 */
final class UpdatePlatformSetting
{
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly CentralAudit $audit,
    ) {}

    public function handle(string $key, mixed $value, ?SuperAdmin $by = null): mixed
    {
        $secret = PlatformSettingsRegistry::isSecret($key);
        $before = $this->settings->get($key);

        $setting = $this->settings->set($key, $value, $by);
        $after = $this->settings->get($key);

        // `null` means nothing was written (a blank submit on a secret — "keep what you have"), and a value that
        // did not move is not a change either: neither earns an audit row.
        if ($setting !== null && $before !== $after) {
            $this->audit->record(
                CentralAuditAction::SettingsChange,
                null,
                $setting,
                ['key' => $key, 'value' => $secret ? '[redacted]' : $before],
                ['key' => $key, 'value' => $secret ? '[redacted]' : $after],
                $by?->id,
            );
        }

        return $secret ? PlatformSettings::mask($after) : $after;
    }
}
