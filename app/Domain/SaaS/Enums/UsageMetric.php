<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

/**
 * `usage_counters.metric` (SCHEMA §2.10). Two shapes behind one enum, and the difference decides the `period`
 * column and the write protocol (SCHEMA §5.8):
 *
 *   GAUGES  (`doctors`, `branches`, `storage_bytes`) count what exists right now. `period = 'current'`, adjusted
 *           by ±n as rows are created and removed, and recomputed nightly from `COUNT(*)` so drift self-heals.
 *   METERS  (everything else) count what happened this calendar month in the CLINIC's timezone.
 *           `period = 'YYYY-MM'`, monotonically increasing, reset by the month rolling over rather than by a job.
 */
enum UsageMetric: string
{
    case Appointments = 'appointments';
    case SmsCredits = 'sms_credits';
    case WhatsappMessages = 'whatsapp_messages';
    case StorageBytes = 'storage_bytes';
    case Doctors = 'doctors';
    case Branches = 'branches';
    case Prescriptions = 'prescriptions';
    case TelemedicineMinutes = 'telemedicine_minutes';
    case AiRequests = 'ai_requests';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** A gauge answers "how many are there"; a meter answers "how many this month". */
    public function isGauge(): bool
    {
        return in_array($this, [self::Doctors, self::Branches, self::StorageBytes], true);
    }

    /** The plan feature that caps this metric, or null when the metric is measured but never capped. */
    public function limitKey(): ?PlanFeatureKey
    {
        return match ($this) {
            self::Branches => PlanFeatureKey::Branches,
            self::Doctors => PlanFeatureKey::Doctors,
            self::Appointments => PlanFeatureKey::AppointmentsMonthly,
            self::SmsCredits => PlanFeatureKey::SmsCreditsMonthly,
            self::StorageBytes => PlanFeatureKey::StorageBytes,
            default => null,
        };
    }

    /** Metrics a plan can cap — the set the super console and the tenant's usage page show against a limit. */
    public function isCapped(): bool
    {
        return $this->limitKey() !== null;
    }

    /** Bytes render as MB/GB, everything else as a plain count. */
    public function isBytes(): bool
    {
        return $this === self::StorageBytes;
    }
}
