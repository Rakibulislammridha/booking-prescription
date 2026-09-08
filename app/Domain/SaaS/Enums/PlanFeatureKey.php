<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

/**
 * `plan_features.feature_key` (SCHEMA §2.3). Two kinds of row behind one enum:
 *
 *   NUMERIC LIMITS  (`branches`, `doctors`, `appointments_monthly`, `sms_credits_monthly`, `storage_bytes`) use
 *                   `limit_value` — `NULL` means unlimited, `0` means the feature is off entirely.
 *   MODULE TOGGLES  (the rest) use `enabled`, and are what the Pennant features of `App\Domain\SaaS\Features`
 *                   resolve from (ARCHITECTURE §8.4). Their Pennant name is the kebab-case spelling
 *                   (`ai_assist` → `ai-assist`), which is what `AiGate` and `WriterPayloadBuilder` already ask for.
 */
enum PlanFeatureKey: string
{
    case Branches = 'branches';
    case Doctors = 'doctors';
    case AppointmentsMonthly = 'appointments_monthly';
    case SmsCreditsMonthly = 'sms_credits_monthly';
    case StorageBytes = 'storage_bytes';
    case Telemedicine = 'telemedicine';
    case AiAssist = 'ai_assist';
    case Whatsapp = 'whatsapp';
    case Ivr = 'ivr';
    case CustomDomain = 'custom_domain';
    case ReportsExport = 'reports_export';
    case WaitingRoomDisplay = 'waiting_room_display';
    case HandwritingMode = 'handwriting_mode';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function isToggle(): bool
    {
        return $this->metric() === null;
    }

    /** @return array<int, self> the module toggles, in the order the console lists them */
    public static function toggles(): array
    {
        return array_values(array_filter(self::cases(), fn (self $k) => $k->isToggle()));
    }

    /** @return array<int, self> the numeric caps, in the order the console lists them */
    public static function limits(): array
    {
        return array_values(array_filter(self::cases(), fn (self $k) => ! $k->isToggle()));
    }

    /** The counter this key caps, or null for a module toggle. */
    public function metric(): ?UsageMetric
    {
        return match ($this) {
            self::Branches => UsageMetric::Branches,
            self::Doctors => UsageMetric::Doctors,
            self::AppointmentsMonthly => UsageMetric::Appointments,
            self::SmsCreditsMonthly => UsageMetric::SmsCredits,
            self::StorageBytes => UsageMetric::StorageBytes,
            default => null,
        };
    }

    /** Pennant feature name for a module toggle (`ai_assist` → `ai-assist`); null for numeric limits. */
    public function featureName(): ?string
    {
        return $this->isToggle() ? str_replace('_', '-', $this->value) : null;
    }

    public static function fromFeatureName(string $name): ?self
    {
        return self::tryFrom(str_replace('-', '_', $name));
    }
}
