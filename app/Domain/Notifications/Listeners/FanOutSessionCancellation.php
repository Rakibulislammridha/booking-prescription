<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Notifications\Actions\QueueNotification;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Services\EventChannels;
use App\Domain\Notifications\Services\NotificationVariables;
use App\Domain\Notifications\Services\RecipientResolver;
use App\Domain\Notifications\Services\SerialAudience;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SessionCancelled;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * BRIEF §5.J "doctor cancelled" — the emergency case the brief singles out: every booked patient must be told, and
 * told once. By the time `SessionCancelled` fires, `CancelSession` has already moved every live serial to
 * `cancelled` with `cancel_reason_code = session_cancelled`, so the audience is exactly those rows.
 *
 * Reached from two directions and correct from both:
 *   - a receptionist cancelling the session directly;
 *   - `DoctorLeaveCreated` (emergency leave) → Scheduling's `CancelSessionsForLeave` → `CancelSession` per
 *     instance, whose `notify_patients` flag rides on the event and is honoured here.
 *
 * `dedupe_key = doctor_cancelled:{serial_id}` means a re-run of the listener, a retried job or a second cancel of
 * an already-cancelled session cannot tell the same patient twice.
 */
final class FanOutSessionCancellation implements ShouldQueue
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
    ) {
        if (Tenancy::check()) {
            $this->forTenant((int) Tenancy::id());
        }
    }

    public function handle(SessionCancelled $event): void
    {
        if (! Tenancy::check() || ($event->values['notify_patients'] ?? true) === false) {
            return;
        }

        $session = SessionInstance::query()->find($event->sessionInstanceId);

        if ($session === null) {
            return;
        }

        $reason = is_string($event->values['reason'] ?? null) ? (string) $event->values['reason'] : null;
        $channels = $this->eventChannels->for(NotificationEvent::DoctorCancelled);

        $this->audience->each(
            $session->serials()
                ->where('status', SerialStatus::Cancelled->value)
                ->where('cancel_reason_code', CancelReason::SessionCancelled->value)
                ->getQuery()
                ->orderBy('position'),
            function (Serial $serial, Patient $patient) use ($session, $reason, $channels): void {
                $probe = new NotificationRequest(event: NotificationEvent::DoctorCancelled, channel: $channels[0], patient: $patient);
                $locale = $this->recipients->locale($probe);

                $variables = [
                    ...$this->variables->base($patient, $locale),
                    ...$this->variables->forSerial($serial, $session, $locale),
                    'reason' => match ($reason) {
                        null => '',
                        'emergency_leave' => (string) __('notifications.cancel_reason.emergency_leave', [], $locale->value),
                        'doctor_leave' => (string) __('notifications.cancel_reason.doctor_leave', [], $locale->value),
                        default => $reason,
                    },
                ];

                foreach ($channels as $channel) {
                    $this->queueNotification->handle(new NotificationRequest(
                        event: NotificationEvent::DoctorCancelled,
                        channel: $channel,
                        patient: $patient,
                        locale: $locale,
                        variables: $variables,
                        notifiable: $session,
                        serialId: $serial->id,
                        dedupeKey: 'doctor_cancelled:'.$serial->id,
                    ));
                }
            },
        );
    }
}
