<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Clinic\Actions\CreateDoctorLeave;
use App\Domain\Clinic\Data\DoctorLeaveData;
use App\Domain\Clinic\Enums\LeaveType;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Serials\Actions\CancelSession;
use App\Domain\Serials\Actions\DelaySession;
use App\Domain\Serials\Actions\PostponeSerial;
use App\Domain\Serials\Actions\TransferSerial;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Patient;
use App\Support\Clock;
use Tests\Feature\Notifications\Concerns\NotificationFixtures;
use Tests\TestCase;

/**
 * BRIEF §5.J's event catalogue wired to its REAL producers — no event is dispatched by hand here except through a
 * domain action, because "the listener works when I fire the event myself" is not the same claim as "a receptionist
 * tapping Cancel tells every booked patient".
 */
final class EventCatalogueTest extends TestCase
{
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        Clock::freeze('2026-03-12 10:00');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    // ---- "3 patients ahead" (REALTIME.md §7) --------------------------------------------------------------

    public function test_call_next_produces_a_three_ahead_notification_for_each_approaching_patient(): void
    {
        $recorder = $this->recordingDriver();
        $session = $this->queueSession($this->queueDoctor(), 'A', counter: 30);
        $serials = [];

        for ($i = 0; $i < 8; $i++) {
            $serial = $this->issue($session, $this->patient(['name' => "Patient {$i}"])->id);
            $this->checkIn($serial);
            $serials[] = $serial;
        }

        $this->callNext($session->fresh());

        $rows = $this->notifications(NotificationEvent::ThreeAhead->value);
        $this->assertCount(4, $rows, 'the four serials at distance 0..3 behind the one being seen');

        foreach ($rows as $row) {
            $this->assertSame(NotificationStatus::Sent, $row->status);
            $this->assertNotNull($row->serial_id);
            $this->assertStringStartsWith('three_ahead:', (string) $row->dedupe_key);
        }

        $this->assertCount(4, $recorder->sent);
        $this->assertStringContainsString('চেম্বার', $recorder->sent[0]['body'], 'a Bangla-preferring patient gets the Bangla wording');
    }

    public function test_calling_next_twice_never_notifies_the_same_patient_twice(): void
    {
        $this->recordingDriver();
        $session = $this->queueSession($this->queueDoctor(), 'A', counter: 30);

        for ($i = 0; $i < 8; $i++) {
            $this->checkIn($this->issue($session, $this->patient()->id));
        }

        $this->callNext($session->fresh());
        $this->callNext($session->fresh());

        $rows = $this->notifications(NotificationEvent::ThreeAhead->value);
        $this->assertCount(5, $rows, 'only the serial that newly entered the window is notified on the second call');
        $this->assertSame(count($rows), count(array_unique(array_map(fn (Notification $n) => $n->serial_id, $rows))));
    }

    public function test_a_serial_without_a_patient_produces_no_three_ahead_notification(): void
    {
        $this->recordingDriver();
        $session = $this->queueSession($this->queueDoctor(), 'A', counter: 30);

        for ($i = 0; $i < 6; $i++) {
            $this->checkIn($this->issue($session));       // walk-ins with no patient record
        }

        $this->callNext($session->fresh());

        $this->assertSame([], $this->notifications(NotificationEvent::ThreeAhead->value));
    }

    // ---- doctor delayed (REALTIME.md §10) ------------------------------------------------------------------

    public function test_a_delay_broadcast_notifies_every_waiting_patient_once(): void
    {
        $this->recordingDriver();
        $session = $this->queueSession($this->queueDoctor(), 'A', counter: 30);

        for ($i = 0; $i < 5; $i++) {
            $this->issue($session, $this->patient()->id);
        }

        app(DelaySession::class)->handle($session->fresh(), 40, $this->queueActor(), 'Doctor in surgery');

        $rows = $this->notifications(NotificationEvent::DoctorDelayed->value);
        $this->assertCount(5, $rows);
        $this->assertStringContainsString('৪০', $rows[0]->body, 'the delay is spoken in Bangla digits');
    }

    /** Tapping +15 twice inside one bucket of `queue.delay_notify_min_change` sends once. */
    public function test_two_delays_inside_one_bucket_send_a_single_notification(): void
    {
        $this->recordingDriver();
        $session = $this->queueSession($this->queueDoctor(), 'A', counter: 30);
        $this->issue($session, $this->patient()->id);

        app(DelaySession::class)->handle($session->fresh(), 40, $this->queueActor());
        app(DelaySession::class)->handle($session->fresh(), 45, $this->queueActor());

        $this->assertCount(1, $this->notifications(NotificationEvent::DoctorDelayed->value));
    }

    public function test_a_delay_that_crosses_into_a_new_bucket_notifies_again(): void
    {
        $this->recordingDriver();
        $session = $this->queueSession($this->queueDoctor(), 'A', counter: 30);
        $this->issue($session, $this->patient()->id);

        app(DelaySession::class)->handle($session->fresh(), 15, $this->queueActor());
        app(DelaySession::class)->handle($session->fresh(), 45, $this->queueActor());

        $this->assertCount(2, $this->notifications(NotificationEvent::DoctorDelayed->value));
    }

    public function test_clearing_the_delay_notifies_nobody(): void
    {
        $this->recordingDriver();
        $session = $this->queueSession($this->queueDoctor(), 'A', counter: 30);
        $this->issue($session, $this->patient()->id);

        app(DelaySession::class)->handle($session->fresh(), 0, $this->queueActor());

        $this->assertSame([], $this->notifications(NotificationEvent::DoctorDelayed->value));
    }

    // ---- doctor cancelled (BRIEF §5.J) ---------------------------------------------------------------------

    public function test_cancelling_a_session_tells_every_booked_patient_exactly_once(): void
    {
        $this->recordingDriver();
        $session = $this->queueSession($this->queueDoctor(), 'A', counter: 30);
        $patients = [];

        for ($i = 0; $i < 6; $i++) {
            $patient = $this->patient(['name' => "Booked {$i}"]);
            $patients[] = $patient;
            $serial = $this->issue($session, $patient->id);

            if ($i < 2) {
                $this->checkIn($serial);       // already at the desk — still must be told
            }
        }

        app(CancelSession::class)->handle($session->fresh(), $this->queueActor(), 'emergency_leave');

        $rows = $this->notifications(NotificationEvent::DoctorCancelled->value);
        $this->assertCount(6, $rows, 'every booked and checked-in patient is told');
        $this->assertSame(6, count(array_unique(array_map(fn (Notification $n) => $n->patient_id, $rows))), 'and each of them exactly once');
        $this->assertStringContainsString('বাতিল', $rows[0]->body);
        $this->assertStringContainsString('জরুরি ছুটি', $rows[0]->body, 'the reason is translated for the patient');
    }

    public function test_cancelling_twice_does_not_notify_twice(): void
    {
        $this->recordingDriver();
        $session = $this->queueSession($this->queueDoctor(), 'A', counter: 30);
        $this->issue($session, $this->patient()->id);

        app(CancelSession::class)->handle($session->fresh(), $this->queueActor(), 'doctor_leave');
        app(CancelSession::class)->handle($session->fresh(), $this->queueActor(), 'doctor_leave');

        $this->assertCount(1, $this->notifications(NotificationEvent::DoctorCancelled->value));
    }

    public function test_a_cancellation_marked_do_not_notify_sends_nothing(): void
    {
        $this->recordingDriver();
        $session = $this->queueSession($this->queueDoctor(), 'A', counter: 30);
        $this->issue($session, $this->patient()->id);

        app(CancelSession::class)->handle($session->fresh(), $this->queueActor(), 'doctor_leave', notifyPatients: false);

        $this->assertSame([], $this->notifications(NotificationEvent::DoctorCancelled->value));
    }

    /** The brief's emergency case, end to end: a doctor's emergency leave reaches every booked patient. */
    public function test_an_emergency_doctor_leave_fans_out_to_every_booked_patient(): void
    {
        $this->recordingDriver();
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor, 'A', counter: 30);

        for ($i = 0; $i < 4; $i++) {
            $this->issue($session, $this->patient()->id);
        }

        app(CreateDoctorLeave::class)->handle(
            new DoctorLeaveData(
                doctorId: $doctor->id,
                startsOn: Clock::today(),
                endsOn: Clock::today(),
                type: LeaveType::Emergency,
                reason: 'Family emergency',
                notifyPatients: true,
            ),
            $this->queueActor(),
        );

        $rows = $this->notifications(NotificationEvent::DoctorCancelled->value);
        $this->assertCount(4, $rows);
        $this->assertSame(4, count(array_unique(array_map(fn (Notification $n) => $n->patient_id, $rows))));
    }

    // ---- booking confirmed ---------------------------------------------------------------------------------

    public function test_a_transfer_tells_the_patient_the_new_serial_and_doctor(): void
    {
        $this->recordingDriver();
        $from = $this->queueSession($this->queueDoctor('dr-a'), 'A', counter: 30);
        $to = $this->queueSession($this->queueDoctor('dr-b'), 'B', counter: 30);
        $patient = $this->patient();
        $serial = $this->issue($from, $patient->id);

        app(TransferSerial::class)->handle($serial->fresh(), $to->fresh(), $this->queueActor());

        $rows = $this->notifications(NotificationEvent::SerialTransferred->value);
        $this->assertCount(1, $rows);
        $this->assertSame($patient->id, $rows[0]->patient_id);
        $this->assertStringContainsString('B-001', $rows[0]->body);
    }

    public function test_a_postpone_tells_the_patient_the_next_session(): void
    {
        $this->recordingDriver();
        $doctor = $this->queueDoctor('dr-c');
        $today = $this->queueSession($doctor, 'A', counter: 30);
        $next = $this->queueSession($doctor, 'B', counter: 30);
        $patient = $this->patient();
        $serial = $this->issue($today, $patient->id);

        app(PostponeSerial::class)->handle($serial->fresh(), $next->fresh(), $this->queueActor());

        $rows = $this->notifications(NotificationEvent::SerialPostponed->value);
        $this->assertCount(1, $rows);
        $this->assertSame($patient->id, $rows[0]->patient_id);
    }

    // ---- reminders are cancelled when the serial dies -------------------------------------------------------

    public function test_cancelling_a_serial_cancels_its_pending_reminders(): void
    {
        $this->recordingDriver();
        $session = $this->queueSession($this->queueDoctor(), 'A', counter: 30);
        $patient = $this->patient();
        $serial = $this->issue($session, $patient->id);

        $reminder = Notification::factory()->scheduled()->create([
            'event_key' => NotificationEvent::ReminderDayBefore,
            'patient_id' => $patient->id,
            'serial_id' => $serial->id,
        ]);

        app(CancelSession::class)->handle($session->fresh(), $this->queueActor(), 'doctor_leave');

        $this->assertSame(NotificationStatus::Cancelled, $reminder->refresh()->status);
        $this->assertSame('serial_cancelled', $reminder->last_error);
    }

    public function test_an_english_preferring_patient_receives_english(): void
    {
        $this->recordingDriver();
        $session = $this->queueSession($this->queueDoctor(), 'A', counter: 30);
        $this->issue($session, Patient::factory()->create(['preferred_language' => 'en'])->id);

        app(DelaySession::class)->handle($session->fresh(), 30, $this->queueActor());

        $rows = $this->notifications(NotificationEvent::DoctorDelayed->value);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('running about 30 minutes late', $rows[0]->body);
    }
}
