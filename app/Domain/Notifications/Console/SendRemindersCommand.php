<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Console;

use App\Domain\Notifications\Actions\QueueNotification;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Jobs\SendNotificationJob;
use App\Domain\Notifications\Services\EventChannels;
use App\Domain\Notifications\Services\NotificationVariables;
use App\Domain\Notifications\Services\RecipientResolver;
use App\Domain\Notifications\Services\SerialAudience;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The one scheduled command this module owns (SCHEMA decision 27 keeps that list closed), with four windows:
 *
 *   --window=day-before   tomorrow's serials, sent at `reminders.day_before_at` clinic time
 *   --window=morning      today's serials, sent at `reminders.morning_at` clinic time
 *   --window=followup     follow-up reminders whose send moment has arrived
 *   --window=due          every `scheduled` row that is now due, plus rows stuck in `queued`
 *
 * Runs inside ONE tenant (`tenants:run notifications:send-reminders --option=window=…`), so "today" and "tomorrow"
 * are that clinic's calendar days in ITS timezone (Asia/Dhaka by default), computed with Clock::today() — a
 * reminder for a session on the 13th must go out on the evening of the 12th in Dhaka, not at 18:00 UTC, which is
 * already midnight there and therefore a different day.
 *
 * The hour gate makes the hourly scheduler idempotent; `--force` bypasses it for tests and manual runs. The
 * `dedupe_key` makes it safe anyway: running this command twelve times in one evening still sends one reminder.
 */
final class SendRemindersCommand extends Command
{
    protected $signature = 'notifications:send-reminders {--window=due : day-before|morning|followup|due} {--force : ignore the clinic-local hour gate} {--limit=5000}';

    protected $description = 'Queue the due reminders for the current tenant (BRIEF §5.J)';

    public function handle(QueueNotification $queueNotification, NotificationVariables $variables, RecipientResolver $recipients, SerialAudience $audience, EventChannels $eventChannels): int
    {
        if (! Tenancy::check()) {
            $this->components->error('notifications:send-reminders must run inside a tenant (use tenants:run)');

            return self::FAILURE;
        }

        return match ((string) $this->option('window')) {
            'day-before' => $this->serialReminders(NotificationEvent::ReminderDayBefore, 1, (string) config('notifications.reminders.day_before_at', '18:00'), $queueNotification, $variables, $recipients, $audience, $eventChannels),
            'morning' => $this->serialReminders(NotificationEvent::ReminderMorning, 0, (string) config('notifications.reminders.morning_at', '07:30'), $queueNotification, $variables, $recipients, $audience, $eventChannels),
            'followup' => $this->dispatchDue([NotificationEvent::FollowupDue->value]),
            'due' => $this->dispatchDue(null),
            default => $this->invalidWindow(),
        };
    }

    private function invalidWindow(): int
    {
        $this->components->error('--window must be one of: day-before, morning, followup, due');

        return self::INVALID;
    }

    /**
     * One reminder per live serial on the target clinic-local date.
     */
    private function serialReminders(
        NotificationEvent $event,
        int $daysAhead,
        string $atLocalTime,
        QueueNotification $queueNotification,
        NotificationVariables $variables,
        RecipientResolver $recipients,
        SerialAudience $audience,
        EventChannels $eventChannels,
    ): int {
        if (! $this->hourHasArrived($atLocalTime)) {
            return self::SUCCESS;
        }

        $date = Clock::today()->addDays($daysAhead)->toDateString();
        $queued = 0;
        $channels = $eventChannels->for($event);

        $audience->each(
            Serial::query()
                ->whereIn('status', [SerialStatus::Booked->value, SerialStatus::CheckedIn->value])
                ->whereHas('sessionInstance', fn ($q) => $q->whereDate('session_date', $date)->whereIn('status', SessionStatus::open()))
                ->with('sessionInstance')
                ->orderBy('id'),
            function (Serial $serial, Patient $patient) use ($event, $channels, $queueNotification, $variables, $recipients, &$queued): void {
                $probe = new NotificationRequest(event: $event, channel: $channels[0], patient: $patient);
                $locale = $recipients->locale($probe);
                $bag = [...$variables->base($patient, $locale), ...$variables->forSerial($serial, $serial->sessionInstance, $locale)];

                foreach ($channels as $channel) {
                    $queued += count($queueNotification->handle(new NotificationRequest(
                        event: $event,
                        channel: $channel,
                        patient: $patient,
                        locale: $locale,
                        variables: $bag,
                        notifiable: $serial,
                        serialId: $serial->id,
                        dedupeKey: $event->value.':'.$serial->id,
                    )));
                }
            },
            (int) $this->option('limit'),
        );

        $this->components->info("{$event->value}: queued {$queued} notification(s) for tenant #".Tenancy::id());

        return self::SUCCESS;
    }

    /**
     * Release rows whose moment has come. A `scheduled` row is flipped to `queued` before the job is dispatched, so
     * a second run of the command a minute later finds nothing to do; a `queued` row is only re-dispatched once it
     * is demonstrably stuck (its job was lost with the worker that held it).
     *
     * @param  array<int, string>|null  $eventKeys
     */
    private function dispatchDue(?array $eventKeys): int
    {
        $now = CarbonImmutable::now();
        $stuckBefore = $now->subMinutes(max(1, (int) config('notifications.retry.stuck_after_minutes', 15)));
        $dispatched = 0;

        Notification::query()
            ->when($eventKeys !== null, fn ($q) => $q->whereIn('event_key', $eventKeys))
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('status', NotificationStatus::Scheduled->value)->whereNotNull('scheduled_for')->where('scheduled_for', '<=', $now))
                ->orWhere(fn ($s) => $s->where('status', NotificationStatus::Queued->value)->where('updated_at', '<=', $stuckBefore))
            )
            ->orderBy('id')
            ->chunkById(200, function (EloquentCollection $rows) use (&$dispatched): bool {
                foreach ($rows as $row) {
                    $row->forceFill(['status' => NotificationStatus::Queued, 'scheduled_for' => null])->save();
                    SendNotificationJob::dispatch($row->id)->onQueue('notifications');
                    $dispatched++;

                    if ($dispatched >= (int) $this->option('limit')) {
                        return false;
                    }
                }

                return true;
            });

        $this->components->info("dispatched {$dispatched} due notification(s) for tenant #".Tenancy::id());

        return self::SUCCESS;
    }

    /** Hourly scheduler + a clinic-local target time: act only in the hour that matches, unless forced. */
    private function hourHasArrived(string $atLocalTime): bool
    {
        if ((bool) $this->option('force')) {
            return true;
        }

        [$hour] = array_pad(array_map('intval', explode(':', $atLocalTime, 2)), 2, 0);

        return Clock::now()->hour === $hour;
    }
}
