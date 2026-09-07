<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Patients\Enums\ConsentStatus;
use App\Domain\Patients\Enums\ConsentType;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientConsent;

/**
 * `patient_consents` is append-only and revocation is a new row (SCHEMA §3.2), so the answer is always "the latest
 * row for this patient and this consent type". SCHEMA decision 23: the sms / whatsapp consent types are exactly the
 * ones this module gates on.
 *
 * Default posture is opt-out (`notifications.consent.require_explicit = false`): a clinic messages the patient it
 * is treating unless that patient has revoked the channel. An OTP the patient just requested is never gated —
 * withholding it would lock them out of their own booking.
 */
final class ConsentGate
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function allows(?Patient $patient, NotificationChannel $channel, NotificationEvent $event): bool
    {
        if ($patient === null || ! $event->honoursConsent()) {
            return true;
        }

        $type = self::consentTypeFor($channel);

        if ($type === null) {
            return true;
        }

        $key = $patient->id.'|'.$type->value;

        return $this->memo[$key] ??= $this->latestAllows($patient, $type);
    }

    public static function consentTypeFor(NotificationChannel $channel): ?ConsentType
    {
        return match ($channel) {
            NotificationChannel::Sms, NotificationChannel::Ivr => ConsentType::Sms,
            NotificationChannel::Whatsapp => ConsentType::Whatsapp,
            default => null,
        };
    }

    public function forget(): void
    {
        $this->memo = [];
    }

    private function latestAllows(Patient $patient, ConsentType $type): bool
    {
        $latest = PatientConsent::query()
            ->where('patient_id', $patient->id)
            ->where('type', $type->value)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if ($latest !== null) {
            return $latest->status === ConsentStatus::Granted;
        }

        return ! (bool) config('notifications.consent.require_explicit', false);
    }
}
