<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Actions\QueueNotification;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Support\Localised;
use App\Domain\Patients\Contracts\OtpSender;
use App\Domain\Patients\Enums\OtpPurpose;
use App\Models\Tenant\Patient;
use App\Tenancy\Facades\Tenancy;

/**
 * The real OtpSender (ARCHITECTURE §6.3): patient portal login and the booking/kiosk OTP now go out over the
 * tenant's SMS gateway instead of into a log file. Bound over `LogOtpSender` by NotificationsServiceProvider.
 *
 * Three deliberate choices:
 *  - the OTP is never consent-gated (NotificationEvent::honoursConsent()) and never held for quiet hours
 *    (isUrgent()) — a patient who just asked for a code is waiting for it, at any hour;
 *  - the code is interpolated into the `otp` template like any other variable, so a clinic can customise the
 *    wording, and Bangla wording is UCS-2-counted like everything else;
 *  - the rendered body containing the code is redacted by `NotificationResource` before it can be displayed in the
 *    outbound log, and never reaches a log line (CONVENTIONS §11 forbids logging OTP codes).
 */
final class SmsOtpSender implements OtpSender
{
    public function __construct(private readonly QueueNotification $queueNotification) {}

    public function send(string $mobile, string $code, OtpPurpose $purpose, string $locale): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $language = Locale::tryFrom($locale) ?? Locale::Bn;
        $patient = Patient::query()->where('mobile', $mobile)->where('is_mobile_owner', true)->first()
            ?? Patient::query()->where('mobile', $mobile)->first();

        $minutes = (int) ceil(((int) config('patients.otp.ttl_seconds', 300)) / 60);

        $this->queueNotification->handle(new NotificationRequest(
            event: NotificationEvent::Otp,
            channel: NotificationChannel::Sms,
            patient: $patient instanceof Patient ? $patient : null,
            recipient: $mobile,
            locale: $language,
            variables: [
                'clinic' => (string) (Tenancy::current()->name ?? ''),
                'patient_name' => $patient->name ?? '',
                'link' => '',
                'code' => $code,
                'minutes' => Localised::number($minutes, $language),
            ],
            payload: ['purpose' => $purpose->value],
        ));
    }
}
