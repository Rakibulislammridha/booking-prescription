<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Actions\CancelAppointment;
use App\Domain\Booking\Actions\RebookFollowUp;
use App\Domain\Booking\Actions\RescheduleAppointment;
use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Data\BookingResult;
use App\Domain\Booking\Data\FeeOverride;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\AppointmentType;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\FeeRule;
use App\Domain\Booking\Events\AppointmentBooked;
use App\Domain\Booking\Events\AppointmentCancelled;
use App\Domain\Booking\Exceptions\AlreadyBooked;
use App\Domain\Booking\Exceptions\DoctorNotBookable;
use App\Domain\Booking\Exceptions\OtpRequired;
use App\Domain\Booking\Exceptions\PatientAmbiguous;
use App\Domain\Booking\Exceptions\SessionNotFound;
use App\Domain\Booking\Listeners\CreateDraftFollowUpAppointment;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Actions\CallSerial;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Domain\Serials\Actions\CompleteConsultation;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Exceptions\PoolExhausted;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialPool as PoolRow;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/** The LOCKED booking flow (BRIEF §5.C) per channel, the fee rules of SCHEMA §5.10, cancel / reschedule / follow-up. */
final class BookAppointmentTest extends TestCase
{
    use SerialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    private function book(BookingRequest $r, ?Actor $actor = null): BookingResult
    {
        return app(BookAppointment::class)->handle($r, $actor ?? $this->staffActor());
    }

    private function counter(SessionInstance $session, string $mobile = '01711111111', string $name = 'Rahima Begum', BookingChannel $channel = BookingChannel::Counter): BookingRequest
    {
        return new BookingRequest(channel: $channel, mobile: $mobile, name: $name, ageYears: 40, sessionPublicId: $session->public_id);
    }

    public function test_counter_booking_creates_patient_serial_and_appointment_with_fee_snapshot(): void
    {
        Event::fake([AppointmentBooked::class]);
        $session = $this->openSession(10, 10, 5);

        $result = $this->book($this->counter($session));

        $this->assertTrue($result->patientCreated);
        $this->assertSame('+8801711111111', $result->patient->mobile);
        $this->assertSame(SerialPool::Counter, $result->serial->pool);
        $this->assertSame(SerialSource::Counter, $result->serial->source);
        $this->assertSame('A-001', $result->serial->display_code);
        $this->assertSame($result->appointment->id, $result->serial->appointment_id);
        $this->assertSame($result->serial->id, $result->appointment->serial_id);
        $this->assertSame(AppointmentStatus::Confirmed, $result->appointment->status);
        $this->assertSame(AppointmentType::New, $result->appointment->type);
        $this->assertSame(FeeRule::New, $result->appointment->fee_rule);
        $this->assertSame(80000, $result->appointment->fee_paisa);
        $this->assertSame(80000, $result->appointment->list_fee_paisa);
        $this->assertSame($session->session_date->toDateString(), $result->appointment->scheduled_date?->toDateString());
        $this->assertSame(1, $result->appointment->booked_by_user_id);
        $this->assertFalse($result->appointment->booked_by_patient);
        Event::assertDispatched(AppointmentBooked::class, fn (AppointmentBooked $e) => $e->channel === BookingChannel::Counter && $e->appointmentId === $result->appointment->id);
        $this->assertAudited(AuditAction::Create, $result->appointment);
    }

    public function test_every_channel_draws_from_its_pool_and_online_never_consumes_counter(): void
    {
        $session = $this->openSession(2, 4, 2);
        $online = fn (string $suffix) => new BookingRequest(channel: BookingChannel::Online, mobile: "017000000{$suffix}", name: "Online {$suffix}", sessionPublicId: $session->public_id, otpVerified: true, clientEventId: $this->ulidFor($suffix));

        $a = $this->book($online('01'), new Actor(source: 'web'));
        $b = $this->book($online('02'), new Actor(source: 'web'));
        $this->assertSame([SerialPool::Online, SerialPool::Online], [$a->serial->pool, $b->serial->pool]);
        $this->assertSame([3, 4], [$a->serial->number, $b->serial->number], 'online pool sits above the counter pool (SERIAL_ENGINE §3.1)');
        $this->assertTrue($a->appointment->booked_by_patient);

        $kiosk = $this->book(new BookingRequest(channel: BookingChannel::Kiosk, mobile: '01744444444', name: 'Kiosk', sessionPublicId: $session->public_id, otpVerified: true), new Actor(source: 'web'));
        $this->assertSame(SerialSource::Kiosk, $kiosk->serial->source);
        $this->assertSame(SerialPool::Online, $kiosk->serial->pool, 'kiosk is a self-service online channel');
        $this->assertSame(5, $kiosk->serial->number);

        $this->book($online('03'), new Actor(source: 'web'));
        $this->assertThrows(fn () => $this->book($online('04'), new Actor(source: 'web')), PoolExhausted::class);
        $this->assertSame(1, PoolRow::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->value('next_number'), 'online exhaustion never spilled into the counter pool');

        $walkin = $this->book($this->counter($session, '01722222222', 'Walk In', BookingChannel::Walkin));
        $this->assertSame(SerialPool::Buffer, $walkin->serial->pool);
        $this->assertSame(SerialSource::Walkin, $walkin->serial->source);
        $this->assertSame(BookingChannel::Walkin, $walkin->appointment->channel);

        $phone = $this->book($this->counter($session, '01733333333', 'Phone', BookingChannel::Phone));
        $this->assertSame(SerialPool::Counter, $phone->serial->pool);
        $this->assertSame(1, $phone->serial->number);
    }

    private function ulidFor(string $suffix): string
    {
        return str_pad(strtoupper(substr(preg_replace('/[^0-9A-Z]/', '', $suffix) ?? '', 0, 6)), 26, '0', STR_PAD_LEFT);
    }

    public function test_fee_rules_new_free_followup_paid_followup_and_override(): void
    {
        $doctor = Doctor::factory()->complete()->create();
        $doctor->profile?->fill(['free_followup_within_days' => 15, 'followup_within_days' => 30, 'new_fee_paisa' => 80000, 'followup_fee_paisa' => 50000])->save();
        $patient = Patient::factory()->create(['mobile' => '+8801755555555']);
        $today = $this->today();

        // a completed visit 7 days ago → free follow-up on day 7 of 15
        $past = SessionInstance::factory()->on($today->subDays(7))->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id]);
        Appointment::factory()->completed()->create(['patient_id' => $patient->id, 'session_instance_id' => $past->id, 'doctor_id' => $doctor->id, 'branch_id' => $past->branch_id, 'scheduled_date' => $past->session_date->toDateString()]);

        $session = $this->openSession(10, 10, 5, $doctor);
        $free = $this->book(new BookingRequest(channel: BookingChannel::Counter, patientPublicId: $patient->public_id, sessionPublicId: $session->public_id));
        $this->assertSame(AppointmentType::Followup, $free->appointment->type);
        $this->assertSame(FeeRule::FollowupFree, $free->appointment->fee_rule);
        $this->assertSame(0, $free->appointment->fee_paisa);
        $this->assertSame(80000, $free->appointment->list_fee_paisa);
        $this->assertStringContainsString($past->session_date->toDateString(), (string) $free->appointment->fee_rule_reason);
        $this->assertSame(FeeRule::FollowupFree, $free->fee->rule);
        $this->assertSame(7, $free->fee->daysSincePrevious);
        $this->assertSame(SerialSource::Counter, $free->serial->source);

        // day 20 of 30 → paid follow-up
        Appointment::query()->whereKey($free->appointment->id)->update(['status' => 'cancelled']);
        $older = SessionInstance::factory()->on($today->subDays(20), 'B', '17:00', '21:00')->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id]);
        Appointment::query()->where('session_instance_id', $past->id)->update(['scheduled_date' => $older->session_date->toDateString()]);
        $paid = $this->book(new BookingRequest(channel: BookingChannel::Counter, patientPublicId: $patient->public_id, sessionPublicId: $session->public_id));
        $this->assertSame(FeeRule::FollowupPaid, $paid->appointment->fee_rule);
        $this->assertSame(50000, $paid->appointment->fee_paisa);

        // day 45 → new
        Appointment::query()->whereKey($paid->appointment->id)->update(['status' => 'cancelled']);
        Appointment::query()->where('status', 'completed')->update(['scheduled_date' => $today->subDays(45)->toDateString()]);
        $new = $this->book(new BookingRequest(channel: BookingChannel::Counter, patientPublicId: $patient->public_id, sessionPublicId: $session->public_id));
        $this->assertSame(FeeRule::New, $new->appointment->fee_rule);
        $this->assertSame(AppointmentType::New, $new->appointment->type);
        $this->assertSame(80000, $new->appointment->fee_paisa);

        // staff override
        Appointment::query()->whereKey($new->appointment->id)->update(['status' => 'cancelled']);
        $waived = $this->book(new BookingRequest(channel: BookingChannel::Counter, patientPublicId: $patient->public_id, sessionPublicId: $session->public_id, feeOverride: new FeeOverride(0, FeeRule::Waived, 'staff family')));
        $this->assertSame(FeeRule::Waived, $waived->appointment->fee_rule);
        $this->assertSame(0, $waived->appointment->fee_paisa);
        $this->assertSame('staff family', $waived->appointment->fee_rule_reason);

        // online adds the doctor's delta
        $doctor->profile?->fill(['online_booking_fee_delta_paisa' => 5000])->save();
        Appointment::query()->whereKey($waived->appointment->id)->update(['status' => 'cancelled']);
        $online = $this->book(new BookingRequest(channel: BookingChannel::Online, patientPublicId: $patient->public_id, sessionPublicId: $session->public_id, otpVerified: true), new Actor(source: 'web'));
        $this->assertSame(85000, $online->appointment->fee_paisa);
    }

    public function test_one_live_booking_per_patient_per_session_and_idempotent_replay(): void
    {
        $session = $this->openSession(10, 10, 5);
        $first = $this->book($this->counter($session));

        $this->assertThrows(fn () => $this->book($this->counter($session)), AlreadyBooked::class);

        $ulid = '01J8ZK4V2Q3W5X6Y7Z8A9B0C1D';
        $a = $this->book(new BookingRequest(channel: BookingChannel::Online, mobile: '01766666666', name: 'Twice', sessionPublicId: $session->public_id, clientEventId: $ulid, otpVerified: true), new Actor(source: 'web'));
        $b = $this->book(new BookingRequest(channel: BookingChannel::Online, mobile: '01766666666', name: 'Twice', sessionPublicId: $session->public_id, clientEventId: $ulid, otpVerified: true), new Actor(source: 'web'));
        $this->assertSame($a->appointment->id, $b->appointment->id);
        $this->assertTrue($b->replayed);
        $this->assertSame(1, Appointment::query()->where('client_event_id', $ulid)->count());
        $this->assertSame(2, Appointment::query()->where('session_instance_id', $session->id)->live()->count());
        $this->assertNotSame($first->serial->id, $a->serial->id);
    }

    public function test_household_ambiguity_otp_requirement_and_doctor_guards(): void
    {
        $session = $this->openSession();
        $owner = Patient::factory()->create(['mobile' => '+8801777777777', 'name' => 'Owner']);
        Patient::factory()->dependentOf($owner)->create(['name' => 'Child']);

        $this->assertThrows(fn () => $this->book(new BookingRequest(channel: BookingChannel::Counter, mobile: '01777777777', sessionPublicId: $session->public_id)), PatientAmbiguous::class);

        $named = $this->book(new BookingRequest(channel: BookingChannel::Counter, mobile: '01777777777', name: 'child', sessionPublicId: $session->public_id));
        $this->assertSame('Child', $named->patient->name);
        $this->assertFalse($named->patientCreated);

        $this->assertThrows(fn () => $this->book(new BookingRequest(channel: BookingChannel::Online, mobile: '01788888888', name: 'No OTP', sessionPublicId: $session->public_id), new Actor(source: 'web')), OtpRequired::class);
        app(Settings::class)->set('kiosk.otp_required', false);
        $noOtp = $this->book(new BookingRequest(channel: BookingChannel::Online, mobile: '01788888888', name: 'No OTP', sessionPublicId: $session->public_id), new Actor(source: 'web'));
        $this->assertSame(SerialSource::Online, $noOtp->serial->source);

        $closedDoctor = Doctor::factory()->complete()->create(['accepts_online_booking' => false]);
        $private = $this->openSession(5, 5, 5, $closedDoctor);
        $this->assertThrows(fn () => $this->book(new BookingRequest(channel: BookingChannel::Online, mobile: '01799999999', name: 'X', sessionPublicId: $private->public_id, otpVerified: true), new Actor(source: 'web')), DoctorNotBookable::class);
        $counterOk = $this->book(new BookingRequest(channel: BookingChannel::Counter, mobile: '01799999999', name: 'X', sessionPublicId: $private->public_id));
        $this->assertSame(SerialPool::Counter, $counterOk->serial->pool);

        $this->assertThrows(fn () => $this->book(new BookingRequest(channel: BookingChannel::Counter, mobile: '01700000000', name: 'Y', sessionPublicId: '01J8ZK4V2Q3W5X6Y7Z8A9B0C1Z')), SessionNotFound::class);
    }

    public function test_session_is_materialised_on_demand_from_doctor_date_and_code(): void
    {
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        $date = $this->today()->addDays(3);
        $this->assertSame(0, SessionInstance::query()->where('doctor_id', $doctor->id)->count());

        $result = $this->book(new BookingRequest(channel: BookingChannel::Counter, mobile: '01712121212', name: 'Late', doctorSlug: $doctor->slug, date: $date, sessionCode: 'a'));

        $this->assertSame($date->toDateString(), $result->appointment->scheduled_date?->toDateString());
        $this->assertSame('A', $result->serial->sessionInstance->session_code);
    }

    public function test_cancel_mirrors_the_serial_and_reports_refund_eligibility(): void
    {
        Event::fake([AppointmentCancelled::class]);
        $session = $this->openSession();
        $result = $this->book($this->counter($session));

        $cancel = app(CancelAppointment::class)->handle($result->appointment, CancelReason::PatientRequest, $this->staffActor(), 'called to cancel');

        $this->assertSame(AppointmentStatus::Cancelled, $cancel['appointment']->status);
        $this->assertSame(CancelReason::PatientRequest, $cancel['appointment']->cancel_reason_code);
        $this->assertTrue($cancel['refund_eligible']);
        $this->assertSame(SerialStatus::Cancelled, $result->serial->fresh()->status);
        $this->assertSame([], $cancel['refund'], 'unpaid: nothing for billing');
        Event::assertDispatched(AppointmentCancelled::class);

        $again = app(CancelAppointment::class)->handle($result->appointment, CancelReason::Other, $this->staffActor());
        $this->assertSame(AppointmentStatus::Cancelled, $again['appointment']->status);
        $this->assertSame(1, Serial::query()->where('session_instance_id', $session->id)->where('status', 'cancelled')->count());

        // the patient may book again in the same session after a cancellation
        $rebooked = $this->book($this->counter($session));
        $this->assertSame($result->patient->id, $rebooked->patient->id);
    }

    public function test_serial_status_changes_flow_into_the_appointment(): void
    {
        $session = $this->openSession();
        $result = $this->book($this->counter($session));

        app(CheckInSerial::class)->handle($result->serial, $this->staffActor());
        $this->assertSame(AppointmentStatus::CheckedIn, $result->appointment->fresh()->status);

        app(CallSerial::class)->handle($result->serial->fresh(), $this->staffActor());
        $this->assertSame(AppointmentStatus::InConsultation, $result->appointment->fresh()->status);

        app(CompleteConsultation::class)->handle($result->serial->fresh(), $this->staffActor());
        $this->assertSame(AppointmentStatus::Completed, $result->appointment->fresh()->status);
    }

    public function test_reschedule_postpones_within_a_doctor_and_transfers_across_doctors_with_a_new_fee(): void
    {
        $doctor = Doctor::factory()->complete()->create();
        $morning = $this->openSession(10, 10, 5, $doctor);
        $evening = SessionInstance::factory()->on($this->today(), 'B', '17:00', '21:00')->quotas(10, 10, 5)->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id]);
        $other = Doctor::factory()->complete()->create();
        $other->profile?->fill(['new_fee_paisa' => 120000])->save();
        $otherSession = SessionInstance::factory()->openToday()->quotas(10, 10, 5)->create(['doctor_id' => $other->id, 'branch_id' => $this->mainBranch()->id, 'fee_new_paisa' => 120000]);

        $result = $this->book($this->counter($morning));
        $moved = app(RescheduleAppointment::class)->handle($result->appointment, $evening, $this->staffActor(), 'late');

        $this->assertSame($evening->id, $moved['appointment']->session_instance_id);
        $this->assertSame($moved['new']->id, $moved['appointment']->serial_id);
        $this->assertSame('B', $moved['new']->sessionInstance->session_code);
        $this->assertSame(SerialStatus::Postponed, $moved['old']->fresh()->status);
        $this->assertSame(AppointmentStatus::Confirmed, $moved['appointment']->fresh()->status);
        $this->assertSame($moved['appointment']->id, $moved['new']->fresh()->appointment_id);

        $transferred = app(RescheduleAppointment::class)->handle($moved['appointment']->fresh(), $otherSession, $this->staffActor(), 'doctor away');
        $fresh = $transferred['appointment']->fresh();
        $this->assertSame($other->id, $fresh->doctor_id);
        $this->assertSame($otherSession->id, $fresh->session_instance_id);
        $this->assertSame(120000, $fresh->fee_paisa, 'fee re-snapshotted for the new doctor');
        $this->assertSame(AppointmentStatus::Confirmed, $fresh->status);
        $this->assertSame(SerialStatus::Cancelled, $transferred['old']->fresh()->status);
        $this->assertSame(CancelReason::Transferred, $transferred['old']->fresh()->cancel_reason_code);
        $this->assertSame($fresh->id, $transferred['new']->fresh()->appointment_id);
    }

    public function test_rebook_follow_up_and_draft_follow_up_listener(): void
    {
        $doctor = Doctor::factory()->complete()->create();
        $patient = Patient::factory()->create();
        $past = SessionInstance::factory()->on($this->today()->subDays(10))->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id]);
        $previous = Appointment::factory()->completed()->create(['patient_id' => $patient->id, 'session_instance_id' => $past->id, 'doctor_id' => $doctor->id, 'branch_id' => $past->branch_id, 'scheduled_date' => $past->session_date->toDateString()]);
        $target = $this->openSession(10, 10, 5, $doctor);

        $result = app(RebookFollowUp::class)->handle($previous, $target, $this->staffActor());
        $this->assertSame(AppointmentType::Followup, $result->appointment->type);
        $this->assertSame(BookingChannel::Followup, $result->appointment->channel);
        $this->assertSame(SerialSource::Followup, $result->serial->source);
        $this->assertSame(SerialPool::Counter, $result->serial->pool, 'staff follow-up draws from the counter pool');
        $this->assertSame(FeeRule::FollowupFree, $result->appointment->fee_rule, 'day 10 of the 15-day free window');

        // a patient rebooking online takes the online pool
        Appointment::query()->whereKey($result->appointment->id)->update(['status' => 'cancelled']);
        $online = app(BookAppointment::class)->handle(new BookingRequest(channel: BookingChannel::Followup, patientPublicId: $patient->public_id, sessionPublicId: $target->public_id, type: AppointmentType::Followup, followUpOfAppointmentId: $previous->id, otpVerified: true), new Actor(patientId: $patient->id, source: 'web'));
        $this->assertSame(SerialPool::Online, $online->serial->pool);

        // the Prescription module's FollowUpScheduled → draft (no serial); RebookFollowUp consumes the draft
        $visitId = $this->visitFor($patient, $doctor);
        app(CreateDraftFollowUpAppointment::class)->handle((object) ['tenantId' => 9001, 'prescriptionId' => 1, 'visitId' => $visitId, 'patientId' => $patient->id, 'doctorId' => $doctor->id, 'branchId' => $this->mainBranch()->id, 'followUpDate' => $this->today()->addDays(7)->toDateString(), 'note' => 'BP recheck', 'createBooking' => true]);
        $draft = Appointment::query()->where('follow_up_of_visit_id', $visitId)->firstOrFail();
        $this->assertSame(AppointmentStatus::Draft, $draft->status);
        $this->assertNull($draft->session_instance_id);
        $this->assertNull($draft->serial_id);
        $this->assertSame(AppointmentType::Followup, $draft->type);
        app(CreateDraftFollowUpAppointment::class)->handle((object) ['visitId' => $visitId, 'patientId' => $patient->id, 'doctorId' => $doctor->id, 'followUpDate' => $this->today()->toDateString(), 'createBooking' => true]);
        $this->assertSame(1, Appointment::query()->where('follow_up_of_visit_id', $visitId)->count(), 'idempotent');

        Appointment::query()->whereKey($online->appointment->id)->update(['status' => 'cancelled']);
        $confirmed = app(RebookFollowUp::class)->handle($draft, $target, $this->staffActor());
        $this->assertSame($visitId, $confirmed->appointment->fresh()->follow_up_of_visit_id);
        $this->assertNull($draft->fresh(), 'the consumed draft is removed');
        $this->assertGreaterThan(0, $confirmed->serial->id);
    }

    /** A visits row for the follow-up reference (the Prescription module's table; its FK on appointments is live in this schema). */
    private function visitFor(Patient $patient, Doctor $doctor): int
    {
        if (! Schema::hasTable('visits')) {
            return 4242;
        }

        return (int) DB::table('visits')->insertGetId([
            'public_id' => (string) Str::ulid(), 'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id,
            'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_appointments_are_tenant_isolated(): void
    {
        $this->assertTenantIsolated('appointments', function (): void {
            $session = $this->openSession();
            $this->book($this->counter($session));
        });
    }
}
