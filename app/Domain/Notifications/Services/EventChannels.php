<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;

/**
 * Which channels an event is delivered on.
 *
 * The default is SMS ALONE, deliberately: every extra channel is a message the clinic pays for, and a patient who
 * gets the same cancellation as an SMS, a WhatsApp message and a robocall is not better informed — they are being
 * shouted at. A clinic that wants a voice call for its feature-phone patients on a cancelled clinic adds
 * `'doctor_cancelled' => ['sms', 'ivr']` to `notifications.event_channels`; the drivers are all in place.
 *
 * Unknown or unusable channel names are dropped rather than throwing: a typo in a deployment config must not stop
 * a patient being told their doctor is not coming.
 */
final class EventChannels
{
    /** @return array<int, NotificationChannel> */
    public function for(NotificationEvent $event): array
    {
        /** @var array<string, mixed> $map */
        $map = (array) config('notifications.event_channels', []);
        $configured = $map[$event->value] ?? null;

        if (! is_array($configured) || $configured === []) {
            return [NotificationChannel::Sms];
        }

        /** @var array<int, string> $allowed */
        $allowed = (array) config('notifications.channels', NotificationChannel::values());

        $channels = [];

        foreach ($configured as $value) {
            $channel = is_string($value) ? NotificationChannel::tryFrom($value) : null;

            if ($channel !== null && in_array($channel->value, $allowed, true)) {
                $channels[$channel->value] = $channel;
            }
        }

        return $channels === [] ? [NotificationChannel::Sms] : array_values($channels);
    }

    /** The first channel of an event — the one a listener uses to resolve the recipient's locale. */
    public function primary(NotificationEvent $event): NotificationChannel
    {
        return $this->for($event)[0];
    }
}
