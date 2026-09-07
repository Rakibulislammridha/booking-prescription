<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\DoctorLeave;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Serial;
use App\Support\Clock;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Notifications\Concerns\NotificationFixtures;
use Tests\TestCase;

/**
 * BRIEF §5.A "emergency cancellation with automatic notification to every booked patient", driven from the screen
 * an admin actually uses. One POST to `panel.clinic.leaves.store` must reach the end of this chain:
 *
 *   CreateDoctorLeave → DoctorLeaveCreated (after commit)
 *     → Scheduling\CancelSessionsForLeave → Serials\CancelSession (per open instance in range)
 *       → every live serial cancelled with `session_cancelled`
 *       → SessionCancelled (carrying notify_patients)
 *         → Notifications\FanOutSessionCancellation → one `doctor_cancelled` message per booked patient
 *
 * Nothing here dispatches an event by hand; the assertions are on the far end of the real chain.
 */
final class EmergencyLeaveScreenTest extends TestCase
{
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        Clock::freeze('2026-03-12 10:00');
        $this->actingAsStaff(Role::HospitalAdmin);
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    public function test_the_leave_screen_renders_with_its_doctors_branches_and_scope(): void
    {
        $doctor = $this->queueDoctor();
        DoctorLeave::factory()->create(['doctor_id' => $doctor->id, 'starts_on' => Clock::today()->addDays(2)->toDateString(), 'ends_on' => Clock::today()->addDays(3)->toDateString()]);

        $this->get('/panel/clinic/leaves')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Leaves/Index')
            ->has('leaves.data', 1)
            ->has('leaves.meta.total')
            ->has('doctors', 1)
            ->has('branch_options')
            ->has('types', 2)
            ->where('filters.scope', 'upcoming')
            ->where('today', Clock::today()->toDateString())
            ->where('can.manage', true));

        $this->get('/panel/clinic/leaves?scope=past')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('leaves.data', 0));
    }

    public function test_an_emergency_leave_cancels_the_sessions_and_queues_a_message_to_every_booked_patient(): void
    {
        $this->recordingDriver();
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor, 'A', counter: 30);

        $serials = [];
        for ($i = 0; $i < 4; $i++) {
            $serials[] = $this->issue($session, $this->patient(['name' => "Booked {$i}"])->id);
        }
        $this->checkIn($serials[0]);   // already at the desk — still must be told

        $this->post('/panel/clinic/leaves', [
            'doctor_id' => $doctor->id,
            'starts_on' => Clock::today()->toDateString(),
            'ends_on' => Clock::today()->toDateString(),
            'type' => 'emergency',
            'reason' => 'পারিবারিক জরুরি অবস্থা',
            'notify_patients' => true,
        ])->assertRedirect();

        // 1. the leave row
        $leave = DoctorLeave::query()->where('doctor_id', $doctor->id)->firstOrFail();
        $this->assertSame('emergency', $leave->type->value);
        $this->assertNotNull($leave->created_by_user_id, 'the acting admin is recorded');

        // 2. the session and every serial in it
        $this->assertSame(SessionStatus::Cancelled, $session->fresh()?->status);
        $this->assertSame('emergency_leave', $session->fresh()->cancel_reason);
        $cancelled = Serial::query()->where('session_instance_id', $session->id)->get();
        $this->assertCount(4, $cancelled);
        foreach ($cancelled as $serial) {
            $this->assertSame(SerialStatus::Cancelled, $serial->status);
            $this->assertSame(CancelReason::SessionCancelled, $serial->cancel_reason_code);
        }

        // 3. one notification per booked patient, and only one
        $rows = $this->notifications(NotificationEvent::DoctorCancelled->value);
        $this->assertCount(4, $rows, 'every booked patient is told');
        $this->assertSame(4, count(array_unique(array_map(fn (Notification $n) => $n->patient_id, $rows))), 'and each of them exactly once');
        $this->assertStringContainsString('জরুরি ছুটি', $rows[0]->body, 'the reason reaches the patient in their own language');
    }

    public function test_an_emergency_leave_marked_do_not_notify_still_cancels_but_sends_nothing(): void
    {
        $this->recordingDriver();
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor, 'A', counter: 30);
        $this->issue($session, $this->patient()->id);

        $this->post('/panel/clinic/leaves', [
            'doctor_id' => $doctor->id,
            'starts_on' => Clock::today()->toDateString(),
            'ends_on' => Clock::today()->toDateString(),
            'type' => 'emergency',
            'notify_patients' => false,
        ])->assertRedirect();

        $this->assertSame(SessionStatus::Cancelled, $session->fresh()?->status);
        $this->assertSame([], $this->notifications(NotificationEvent::DoctorCancelled->value));
    }

    public function test_a_planned_leave_cancels_with_its_own_reason(): void
    {
        $this->recordingDriver();
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor, 'A', counter: 30);
        $this->issue($session, $this->patient()->id);

        $this->post('/panel/clinic/leaves', [
            'doctor_id' => $doctor->id,
            'starts_on' => Clock::today()->toDateString(),
            'ends_on' => Clock::today()->addDay()->toDateString(),
            'type' => 'planned',
        ])->assertRedirect();

        $this->assertSame('doctor_leave', $session->fresh()?->cancel_reason);
        $this->assertCount(1, $this->notifications(NotificationEvent::DoctorCancelled->value));
    }

    public function test_overlapping_leave_is_a_domain_error_and_a_withdrawn_leave_frees_the_range(): void
    {
        $doctor = $this->queueDoctor();

        $this->post('/panel/clinic/leaves', [
            'doctor_id' => $doctor->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-03', 'type' => 'planned',
        ])->assertRedirect();

        $this->post('/panel/clinic/leaves', [
            'doctor_id' => $doctor->id, 'starts_on' => '2026-10-03', 'ends_on' => '2026-10-05', 'type' => 'planned',
        ])->assertSessionHasErrors('domain');

        $leave = DoctorLeave::query()->firstOrFail();
        $this->delete('/panel/clinic/leaves/'.$leave->id)->assertRedirect();
        $this->assertTrue($leave->fresh()->is_cancelled);

        $this->post('/panel/clinic/leaves', [
            'doctor_id' => $doctor->id, 'starts_on' => '2026-10-03', 'ends_on' => '2026-10-05', 'type' => 'planned',
        ])->assertRedirect();
        $this->assertSame(2, DoctorLeave::query()->count());
    }

    public function test_ends_on_must_not_precede_starts_on(): void
    {
        $doctor = $this->queueDoctor();

        $this->post('/panel/clinic/leaves', [
            'doctor_id' => $doctor->id, 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-01', 'type' => 'planned',
        ])->assertSessionHasErrors('ends_on');
    }

    public function test_a_doctor_records_only_their_own_leave(): void
    {
        $other = $this->queueDoctor('dr-other');
        $doctorUser = $this->actingAsDoctor();
        $own = $doctorUser->doctor()->firstOrFail();

        $this->post('/panel/clinic/leaves', [
            'doctor_id' => $own->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02', 'type' => 'planned',
        ])->assertRedirect();

        $this->post('/panel/clinic/leaves', [
            'doctor_id' => $other->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-02', 'type' => 'planned',
        ])->assertForbidden();
    }
}
