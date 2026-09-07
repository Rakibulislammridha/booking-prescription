<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Prescription\Events\FollowUpScheduled;
use App\Domain\Serials\Actions\CancelSerial;
use App\Domain\Serials\Enums\CancelReason;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Tests\Feature\Notifications\Concerns\NotificationFixtures;
use Tests\TestCase;

/**
 * Reminder scheduling in the clinic's timezone. The trap this suite exists to catch: 18:00 in Dhaka is 12:00 UTC,
 * and 23:30 Dhaka is already the PREVIOUS day in UTC — a reminder computed in UTC picks the wrong day's serials
 * for half the evening, every evening.
 */
final class RemindersTest extends TestCase
{
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->recordingDriver();
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    private function sessionOn(string $localDate, string $start = '09:00'): SessionInstance
    {
        $date = CarbonImmutable::parse($localDate, Clock::timezone());

        return SessionInstance::factory()->openToday()->quotas(30, 10, 5)->create([
            'doctor_id' => $this->queueDoctor('dr-'.substr(md5($localDate.$start), 0, 6))->id,
            'branch_id' => $this->mainBranch()->id,
            'session_date' => $date->toDateString(),
            'session_code' => 'A',
            'planned_start_at' => $date->setTimeFromTimeString($start)->utc(),
            'planned_end_at' => $date->setTimeFromTimeString('13:00')->utc(),
        ]);
    }

    private function reminders(string $window, bool $force = true): int
    {
        return $this->artisan('notifications:send-reminders', ['--window' => $window, '--force' => $force])->execute();
    }

    public function test_the_day_before_window_picks_tomorrows_clinic_local_serials(): void
    {
        Clock::freeze('2026-03-12 18:05');
        $tomorrow = $this->sessionOn('2026-03-13');
        $today = $this->sessionOn('2026-03-12');

        $this->issue($tomorrow, $this->patient()->id);
        $this->issue($today, $this->patient()->id);

        $this->reminders('day-before');

        $rows = $this->notifications(NotificationEvent::ReminderDayBefore->value);
        $this->assertCount(1, $rows, "only tomorrow's serial is reminded");
        $this->assertStringContainsString('আগামীকাল', $rows[0]->body);
    }

    /**
     * 23:30 in Dhaka is 17:30 UTC of the SAME day, but 00:30 Dhaka is 18:30 UTC of the PREVIOUS day. A reminder
     * that computed "tomorrow" from UTC would target 13 March at 23:30 on the 12th and then wrongly target the
     * 13th again at 00:30 on the 13th. Clock::today() uses the tenant timezone, so both are correct.
     */
    public function test_the_date_boundary_is_the_clinics_midnight_not_utcs(): void
    {
        Clock::freeze('2026-03-12 23:30');
        $this->assertSame('2026-03-12', Clock::today()->toDateString());
        $this->assertSame('2026-03-12', now()->setTimezone('Asia/Dhaka')->toDateString());
        $this->assertSame('2026-03-12', now()->utc()->toDateString(), 'still the 12th in UTC — the easy case');

        Clock::freeze('2026-03-13 00:30');
        $this->assertSame('2026-03-13', Clock::today()->toDateString(), 'the clinic has turned the page');
        $this->assertSame('2026-03-12', now()->utc()->toDateString(), 'UTC has not — this is the bug the timezone rule prevents');
    }

    public function test_the_day_before_window_after_the_clinics_midnight_targets_the_new_tomorrow(): void
    {
        Clock::freeze('2026-03-13 00:30');
        $this->issue($this->sessionOn('2026-03-14'), $this->patient()->id);
        $this->issue($this->sessionOn('2026-03-13'), $this->patient()->id);

        $this->reminders('day-before');

        $rows = $this->notifications(NotificationEvent::ReminderDayBefore->value);
        $this->assertCount(1, $rows);

        $sessionId = Serial::query()->whereKey($rows[0]->serial_id)->value('session_instance_id');
        $sessionDate = SessionInstance::query()->whereKey($sessionId)->value('session_date');
        $this->assertSame('2026-03-14', $sessionDate?->toDateString());
    }

    public function test_the_morning_window_picks_todays_serials(): void
    {
        Clock::freeze('2026-03-12 07:35');
        $this->issue($this->sessionOn('2026-03-12'), $this->patient()->id);
        $this->issue($this->sessionOn('2026-03-13'), $this->patient()->id);

        $this->reminders('morning');

        $rows = $this->notifications(NotificationEvent::ReminderMorning->value);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('আজ', $rows[0]->body);
    }

    public function test_running_the_window_twice_sends_one_reminder(): void
    {
        Clock::freeze('2026-03-12 18:05');
        $this->issue($this->sessionOn('2026-03-13'), $this->patient()->id);

        $this->reminders('day-before');
        $this->reminders('day-before');

        $this->assertCount(1, $this->notifications(NotificationEvent::ReminderDayBefore->value));
    }

    public function test_the_hourly_gate_keeps_the_scheduler_from_reminding_at_the_wrong_hour(): void
    {
        Clock::freeze('2026-03-12 11:00');
        $this->issue($this->sessionOn('2026-03-13'), $this->patient()->id);

        $this->artisan('notifications:send-reminders', ['--window' => 'day-before'])->assertExitCode(0);

        $this->assertSame([], $this->notifications(NotificationEvent::ReminderDayBefore->value));
    }

    public function test_a_cancelled_serial_gets_no_reminder(): void
    {
        Clock::freeze('2026-03-12 18:05');
        $session = $this->sessionOn('2026-03-13');
        $serial = $this->issue($session, $this->patient()->id);
        app(CancelSerial::class)->handle($serial->fresh(), CancelReason::PatientRequest, $this->queueActor());

        $this->reminders('day-before');

        $this->assertSame([], $this->notifications(NotificationEvent::ReminderDayBefore->value));
    }

    // ---- follow-up ------------------------------------------------------------------------------------------

    public function test_a_follow_up_is_scheduled_now_and_released_on_the_day(): void
    {
        Clock::freeze('2026-03-12 12:00');
        $patient = $this->patient();

        event(new FollowUpScheduled(
            tenantId: (int) Tenancy::id(),
            prescriptionId: 1,
            visitId: 77,
            patientId: $patient->id,
            doctorId: $this->queueDoctor('dr-followup')->id,
            branchId: $this->mainBranch()->id,
            followUpDate: '2026-04-10',
            note: null,
            createBooking: false,
        ));

        $row = Notification::query()->where('event_key', NotificationEvent::FollowupDue->value)->firstOrFail();
        $this->assertSame(NotificationStatus::Scheduled, $row->status);
        $this->assertSame('2026-04-09 09:00', $row->scheduled_for?->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'), 'one day before, at the configured clinic-local hour');
        $this->assertSame('followup_due:77', $row->dedupe_key === null ? null : substr($row->dedupe_key, 0, 15));

        // nothing is due yet
        $this->reminders('followup');
        $this->assertSame(NotificationStatus::Scheduled, $row->refresh()->status);

        // …and on the day it is released and sent
        Clock::freeze('2026-04-09 09:05');
        $this->reminders('followup');
        $this->assertSame(NotificationStatus::Sent, $row->refresh()->status);
    }

    public function test_the_due_window_releases_any_scheduled_row(): void
    {
        Clock::freeze('2026-03-12 08:00');
        $row = Notification::factory()->scheduled(CarbonImmutable::now()->subMinute())->create();

        $this->reminders('due');

        $this->assertSame(NotificationStatus::Sent, $row->refresh()->status);
    }

    public function test_the_due_window_leaves_a_future_row_alone(): void
    {
        Clock::freeze('2026-03-12 08:00');
        $row = Notification::factory()->scheduled(CarbonImmutable::now()->addDay())->create();

        $this->reminders('due');

        $this->assertSame(NotificationStatus::Scheduled, $row->refresh()->status);
    }

    public function test_an_unknown_window_is_rejected(): void
    {
        $this->assertSame(2, $this->reminders('nonsense'));
    }
}
