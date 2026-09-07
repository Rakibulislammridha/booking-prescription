<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Notification> */
final class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_key' => NotificationEvent::BookingConfirmed,
            'channel' => NotificationChannel::Sms,
            'patient_id' => Patient::factory(),
            'user_id' => null,
            'notifiable_type' => null,
            'notifiable_id' => null,
            'serial_id' => null,
            'notification_template_id' => null,
            'recipient' => '+8801712345678',
            'locale' => Locale::Bn,
            'subject' => null,
            'body' => 'Serial A-012 confirmed.',
            'payload' => [],
            'status' => NotificationStatus::Queued,
            'scheduled_for' => null,
            'attempts' => 0,
        ];
    }

    public function scheduled(?CarbonImmutable $at = null): static
    {
        return $this->state(fn () => ['status' => NotificationStatus::Scheduled, 'scheduled_for' => $at ?? CarbonImmutable::now()->addHour()]);
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => NotificationStatus::Sent, 'sent_at' => CarbonImmutable::now(), 'attempts' => 1, 'segments' => 1]);
    }

    public function failed(string $error = 'gateway_rejected'): static
    {
        return $this->state(fn () => ['status' => NotificationStatus::Failed, 'attempts' => 3, 'last_error' => $error]);
    }
}
