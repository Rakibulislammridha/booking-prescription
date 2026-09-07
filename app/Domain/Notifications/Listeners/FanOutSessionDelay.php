<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Notifications\Actions\QueueNotification;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Services\EventChannels;
use App\Domain\Notifications\Services\NotificationVariables;
use App\Domain\Notifications\Services\RecipientResolver;
use App\Domain\Notifications\Services\SerialAudience;
use App\Domain\Notifications\Support\Localised;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SessionDelayed;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * REALTIME.md §10 — "doctor running 40 minutes late, one tap notifies everyone waiting". One notification per
 * `booked|checked_in` serial, deduped per (serial, delay bucket):
 *
 *     dedupe_key = doctor_delayed:{serial_id}:{floor(delay_minutes / queue.delay_notify_min_change)}
 *
 * so tapping +15 twice inside the same 10-minute bucket sends once, while a genuine escalation from 15 to 45
 * minutes lands in a new bucket and does notify. Clearing the delay (0) sends nothing at all.
 */
final class FanOutSessionDelay implements ShouldQueue
{
    use InteractsWithQueue, TenantAware;

    public string $queue = 'notifications';

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        private readonly QueueNotification $queueNotification,
        private readonly NotificationVariables $variables,
        private readonly RecipientResolver $recipients,
        private readonly SerialAudience $audience,
        private readonly EventChannels $eventChannels,
        private readonly Settings $settings,
    ) {
        if (Tenancy::check()) {
            $this->forTenant((int) Tenancy::id());
        }
    }

    public function handle(SessionDelayed $event): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $delayMinutes = (int) ($event->values['delay_minutes'] ?? 0);

        if ($delayMinutes <= 0) {
            return;
        }

        $session = SessionInstance::query()->find($event->sessionInstanceId);

        if ($session === null) {
            return;
        }

        $bucket = intdiv($delayMinutes, max(1, $this->bucketWidth()));
        $expected = $session->planned_start_at->addMinutes($delayMinutes);
        $channels = $this->eventChannels->for(NotificationEvent::DoctorDelayed);

        $this->audience->each(
            $session->serials()
                ->whereIn('status', [SerialStatus::Booked->value, SerialStatus::CheckedIn->value])
                ->getQuery()
                ->orderBy('position'),
            function (Serial $serial, Patient $patient) use ($session, $delayMinutes, $expected, $bucket, $channels): void {
                $probe = new NotificationRequest(event: NotificationEvent::DoctorDelayed, channel: $channels[0], patient: $patient);
                $locale = $this->recipients->locale($probe);

                $variables = [
                    ...$this->variables->base($patient, $locale),
                    ...$this->variables->forSerial($serial, $session, $locale),
                    'delay_minutes' => Localised::number($delayMinutes, $locale),
                    'expected_start_time' => Localised::time($expected, $locale),
                ];

                foreach ($channels as $channel) {
                    $this->queueNotification->handle(new NotificationRequest(
                        event: NotificationEvent::DoctorDelayed,
                        channel: $channel,
                        patient: $patient,
                        locale: $locale,
                        variables: $variables,
                        notifiable: $session,
                        serialId: $serial->id,
                        dedupeKey: "doctor_delayed:{$serial->id}:{$bucket}",
                    ));
                }
            },
        );
    }

    private function bucketWidth(): int
    {
        $value = $this->settings->get('queue.delay_notify_min_change');

        return is_numeric($value) ? max(1, (int) $value) : 10;
    }
}
