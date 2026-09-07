<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Models\Tenant\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationTemplate> */
final class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_key' => NotificationEvent::BookingConfirmed,
            'channel' => NotificationChannel::Sms,
            'locale' => Locale::En,
            'subject' => null,
            'body' => '{{clinic}}: serial {{serial}} with {{doctor}} on {{date}} at {{time}}.',
            'provider_template_id' => null,
            'is_active' => true,
            'updated_by_user_id' => null,
        ];
    }

    public function bangla(): static
    {
        return $this->state(fn () => [
            'locale' => Locale::Bn,
            'body' => '{{clinic}}: {{doctor}}-এর সিরিয়াল {{serial}}, {{date}} তারিখ {{time}}টায়।',
        ]);
    }

    public function forEvent(NotificationEvent $event, NotificationChannel $channel = NotificationChannel::Sms, Locale $locale = Locale::En): static
    {
        return $this->state(fn () => ['event_key' => $event, 'channel' => $channel, 'locale' => $locale]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
