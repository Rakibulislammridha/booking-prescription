<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Notifications\Actions\QueueNotification;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Services\EventChannels;
use App\Domain\Notifications\Services\NotificationVariables;
use App\Domain\Notifications\Services\RecipientResolver;
use App\Domain\Notifications\Support\Localised;
use App\Domain\Prescription\Events\FollowUpScheduled;
use App\Models\Tenant\Patient;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * BRIEF §5.J "follow-up due". The doctor writes a follow-up date months ahead, so the reminder is written NOW as a
 * `scheduled` row and delivered by `notifications:send-reminders --window=due` on the day — the clinic does not
 * have to keep a job alive for eleven weeks.
 *
 * The send moment is `notifications.reminders.followup_at` clinic-local, `followup_lead_days` before the date; a
 * follow-up dated for today or the past is sent at once rather than skipped.
 */
final class ScheduleFollowUpReminder implements ShouldQueue
{
    use InteractsWithQueue, TenantAware;

    public string $queue = 'notifications';

    public int $tries = 3;

    public function __construct(
        private readonly QueueNotification $queueNotification,
        private readonly NotificationVariables $variables,
        private readonly RecipientResolver $recipients,
        private readonly EventChannels $channels,
    ) {
        if (Tenancy::check()) {
            $this->forTenant((int) Tenancy::id());
        }
    }

    public function handle(FollowUpScheduled $event): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $patient = Patient::query()->find($event->patientId);

        if (! $patient instanceof Patient) {
            return;
        }

        $probe = new NotificationRequest(event: NotificationEvent::FollowupDue, channel: $this->channels->primary(NotificationEvent::FollowupDue), patient: $patient);
        $locale = $this->recipients->locale($probe);
        $followUpDate = CarbonImmutable::parse($event->followUpDate, Clock::timezone())->startOfDay();

        $variables = [
            ...$this->variables->base($patient, $locale),
            'doctor' => $this->variables->doctorName($this->variables->doctor($event->doctorId), $locale),
            'date' => Localised::date($followUpDate, $locale),
        ];

        foreach ($this->channels->for(NotificationEvent::FollowupDue) as $channel) {
            $this->queueNotification->handle(new NotificationRequest(
                event: NotificationEvent::FollowupDue,
                channel: $channel,
                patient: $patient,
                locale: $locale,
                variables: $variables,
                serialId: null,
                dedupeKey: 'followup_due:'.$event->visitId,
                scheduledFor: $this->sendAt($followUpDate),
            ));
        }
    }

    private function sendAt(CarbonImmutable $followUpDate): CarbonImmutable
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', (string) config('notifications.reminders.followup_at', '09:00'), 2)), 2, 0);
        $lead = max(0, (int) config('notifications.reminders.followup_lead_days', 1));
        $at = $followUpDate->subDays($lead)->setTime($hour, $minute)->setTimezone('UTC');
        $now = CarbonImmutable::now();

        return $at->lessThan($now) ? $now : $at;
    }
}
