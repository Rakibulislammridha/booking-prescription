<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Billing\Actions\RecordCashPayment;
use App\Domain\Billing\Gateways\GatewayManager;
use App\Domain\Booking\Actions\ReleaseExpiredHold;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Booking\Schedule;
use App\Domain\Booking\Services\AdvancePaymentPolicy;
use App\Domain\Booking\Services\NoOnlinePayment;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Refund;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Support\Scheduling\RegistersSchedule;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/**
 * `booking:expire-holds` — an unpaid advance-payment hold gives its serial back (BRIEF §5.C). The release goes
 * through CancelAppointment → CancelSerial with reason `no_payment`, so the number, the counts and the queue
 * version all move exactly as they would for a desk cancellation (SERIAL_ENGINE §6).
 */
final class ExpireAdvancePaymentHoldsTest extends TestCase
{
    use BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        app(Settings::class)->set('kiosk.otp_required', false);
        config(['billing.gateways.driver' => 'log']);
        app(GatewayManager::class)->forget();
        app(Settings::class)->set(NoOnlinePayment::SETTING, true);
    }

    /** @param  array<string, mixed>  $params */
    private function url(string $name, array $params = []): string
    {
        return route($name, $params, false);
    }

    private function advanceSession(): SessionInstance
    {
        $doctor = Doctor::factory()->complete()->create();
        $doctor->profile?->forceFill(['advance_payment_required' => true])->save();

        return $this->openSession(10, 10, 5, $doctor->load('profile'));
    }

    private function hold(SessionInstance $session, string $mobile, string $ulid): Appointment
    {
        $this->post($this->url('site.booking.store'), [
            'session' => $session->public_id, 'channel' => 'online', 'mobile' => $mobile,
            'name' => 'Held Patient', 'sex' => 'f', 'age_years' => 30, 'client_event_id' => $ulid,
        ]);

        return Appointment::query()->where('client_event_id', $ulid)->firstOrFail();
    }

    public function test_an_unpaid_hold_past_the_window_is_cancelled_and_its_serial_released(): void
    {
        $session = $this->advanceSession();
        $held = $this->hold($session, '01712000001', '01J8ZK4V2Q3W5X6Y7Z8A9B0E01');
        $this->assertSame(AppointmentStatus::Pending, $held->status);

        $this->travel(31)->minutes();
        $this->artisan('booking:expire-holds')->assertExitCode(0)->expectsOutputToContain('1 unpaid hold(s) released');

        $held->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $held->status);
        $this->assertSame(CancelReason::NoPayment, $held->cancel_reason_code);
        $this->assertNotNull($held->cancelled_at);

        $serial = Serial::query()->findOrFail($held->serial_id);
        $this->assertSame(SerialStatus::Cancelled, $serial->status);
        $this->assertSame(CancelReason::NoPayment, $serial->cancel_reason_code);
        $this->assertSame(1, $session->refresh()->cancelled_count, 'the counts were recalculated, so the queue no longer holds the number');
        $this->assertSame(0, $session->booked_count);
    }

    public function test_a_paid_hold_and_a_hold_inside_the_window_are_left_alone(): void
    {
        $session = $this->advanceSession();
        $paid = $this->hold($session, '01712000002', '01J8ZK4V2Q3W5X6Y7Z8A9B0E02');
        $fresh = $this->hold($session, '01712000003', '01J8ZK4V2Q3W5X6Y7Z8A9B0E03');

        $this->actingAsStaff(Role::Receptionist);
        app(RecordCashPayment::class)->handle($paid->refresh(), $paid->fee_paisa, 'C-PAID-'.$paid->id, $this->staffActor());
        $this->assertSame(PaymentStatus::Paid, $paid->refresh()->payment_status);

        // 20 minutes in: neither the paid one (no longer unpaid) nor the fresh one (still inside the window) qualifies.
        $this->travel(20)->minutes();
        $this->artisan('booking:expire-holds')->assertExitCode(0)->expectsOutputToContain('0 unpaid hold(s) released');

        $this->assertSame(AppointmentStatus::Confirmed, $paid->refresh()->status);
        $this->assertSame(AppointmentStatus::Pending, $fresh->refresh()->status);

        // Past the window the paid one still stands and only the unpaid one goes.
        $this->travel(20)->minutes();
        $this->artisan('booking:expire-holds')->assertExitCode(0)->expectsOutputToContain('1 unpaid hold(s) released');

        $this->assertSame(AppointmentStatus::Confirmed, $paid->refresh()->status);
        $this->assertSame(AppointmentStatus::Cancelled, $fresh->refresh()->status);
        $this->assertSame(SerialStatus::Booked, Serial::query()->findOrFail($paid->serial_id)->status);
    }

    /**
     * The race the sweep must lose gracefully: it has READ the row (still pending + unpaid + old) and is about to
     * cancel it, and the advance lands in between — a gateway callback, or counter cash as here. The decision is
     * taken under the row lock on the row as it is THEN, so the paid, confirmed booking survives and nothing is
     * refunded. `$stale` is exactly the instance the sweep's candidate read would be holding.
     */
    public function test_a_hold_paid_between_the_sweeps_read_and_its_lock_keeps_its_booking(): void
    {
        $session = $this->advanceSession();
        $held = $this->hold($session, '01712000006', '01J8ZK4V2Q3W5X6Y7Z8A9B0E06');

        $this->travel(31)->minutes();
        $cutoff = now()->subMinutes(30);
        $stale = Appointment::query()->whereKey($held->id)->firstOrFail();

        $this->actingAsStaff(Role::Receptionist);
        app(RecordCashPayment::class)->handle($held->refresh(), $held->fee_paisa, 'C-RACE-'.$held->id, $this->staffActor());
        $this->assertSame(AppointmentStatus::Confirmed, $held->refresh()->status, 'precondition: the money confirmed the hold');

        $this->assertNull(app(ReleaseExpiredHold::class)->handle($stale, $cutoff, Actor::system(), __('booking.hold.expired')), 'a settled hold is skipped, not released');

        $held->refresh();
        $this->assertSame(AppointmentStatus::Confirmed, $held->status);
        $this->assertSame(PaymentStatus::Paid, $held->payment_status);
        $this->assertNull($held->cancelled_at);
        $this->assertNull($held->cancel_reason_code);
        $this->assertSame(SerialStatus::Booked, Serial::query()->findOrFail($held->serial_id)->status, 'the number is still the patient\'s');
        $this->assertSame(0, $session->refresh()->cancelled_count);
        $this->assertSame(0, Refund::query()->count(), 'no refund was raised on a booking that was never cancelled');
    }

    /**
     * The same interleaving through the command itself: the advance is recorded the moment the sweep's candidate
     * SELECT has returned and before it takes the row lock. The sweep must report the row as skipped, not released.
     */
    public function test_the_sweep_reports_a_hold_settled_after_its_candidate_read_as_skipped(): void
    {
        $session = $this->advanceSession();
        $held = $this->hold($session, '01712000007', '01J8ZK4V2Q3W5X6Y7Z8A9B0E07');
        $stillHeld = $this->hold($session, '01712000008', '01J8ZK4V2Q3W5X6Y7Z8A9B0E08');
        $this->travel(31)->minutes();

        $receptionist = $this->actingAsStaff(Role::Receptionist);
        $actor = Actor::user((int) $receptionist->id, Role::Receptionist->value);
        $paid = false;

        DB::listen(function (QueryExecuted $query) use ($held, $actor, &$paid): void {
            // The candidate read is the only statement that filters appointments on payment_status.
            if ($paid || ! str_contains($query->sql, 'from "appointments"') || ! str_contains($query->sql, '"payment_status"')) {
                return;
            }

            $paid = true;
            app(RecordCashPayment::class)->handle($held->refresh(), $held->fee_paisa, 'C-LATE-'.$held->id, $actor);
        });

        $this->artisan('booking:expire-holds')->assertExitCode(0)
            ->expectsOutputToContain('1 unpaid hold(s) released')
            ->expectsOutputToContain('1 hold(s) skipped');

        $this->assertTrue($paid, 'the payment was injected between the candidate read and the lock');
        $this->assertSame(AppointmentStatus::Confirmed, $held->refresh()->status);
        $this->assertSame(PaymentStatus::Paid, $held->payment_status);
        $this->assertSame(SerialStatus::Booked, Serial::query()->findOrFail($held->serial_id)->status);
        $this->assertSame(AppointmentStatus::Cancelled, $stillHeld->refresh()->status, 'the genuinely unpaid hold in the same chunk still goes');
        $this->assertSame(Refund::query()->count(), 0);
    }

    /** The other order: the sweep already cancelled; money arriving afterwards must not confirm a cancelled booking. */
    public function test_money_arriving_after_the_release_does_not_resurrect_the_cancelled_hold(): void
    {
        $session = $this->advanceSession();
        $held = $this->hold($session, '01712000011', '01J8ZK4V2Q3W5X6Y7Z8A9B0E11');

        $this->travel(31)->minutes();
        $this->artisan('booking:expire-holds')->assertExitCode(0)->expectsOutputToContain('1 unpaid hold(s) released');
        $this->assertSame(AppointmentStatus::Cancelled, $held->refresh()->status);

        $this->actingAsStaff(Role::Receptionist);
        app(RecordCashPayment::class)->handle($held->refresh(), $held->fee_paisa, 'C-AFTER-'.$held->id, $this->staffActor());

        $held->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $held->status, 'paid, but the cancellation stands — the ledger only confirms a PENDING hold');
        $this->assertSame(PaymentStatus::Paid, $held->payment_status, 'and the money is on the record for the desk to refund');
        $this->assertSame(SerialStatus::Cancelled, Serial::query()->findOrFail($held->serial_id)->status);
    }

    public function test_the_window_is_the_tenant_setting_and_the_command_is_idempotent(): void
    {
        $this->assertSame(30, app(AdvancePaymentPolicy::class)->holdMinutes());
        app(Settings::class)->set(AdvancePaymentPolicy::HOLD_SETTING, 120);
        $this->assertSame(120, app(AdvancePaymentPolicy::class)->holdMinutes());

        $session = $this->advanceSession();
        $held = $this->hold($session, '01712000004', '01J8ZK4V2Q3W5X6Y7Z8A9B0E04');

        $this->travel(31)->minutes();
        $this->artisan('booking:expire-holds')->assertExitCode(0)->expectsOutputToContain('0 unpaid hold(s) released');
        $this->assertSame(AppointmentStatus::Pending, $held->refresh()->status);

        // An explicit override still works, and running it twice changes nothing the second time.
        $this->artisan('booking:expire-holds', ['--minutes' => 30])->assertExitCode(0)->expectsOutputToContain('1 unpaid hold(s) released');
        $this->assertSame(AppointmentStatus::Cancelled, $held->refresh()->status);

        $cancelledAt = $held->cancelled_at;
        $this->artisan('booking:expire-holds', ['--minutes' => 30])->assertExitCode(0)->expectsOutputToContain('0 unpaid hold(s) released');
        $this->assertEquals($cancelledAt, $held->refresh()->cancelled_at);
    }

    /**
     * The desk has to be able to tell "this number is held until the money arrives, and may vanish" from "this
     * patient is booked and coming" — so the board carries the hold's own deadline, and it stops carrying one the
     * moment the hold is settled or swept (SerialPresenter::holdExpiresAt mirrors the command's predicate).
     */
    public function test_the_board_carries_the_holds_deadline_until_it_is_paid_or_swept(): void
    {
        $session = $this->advanceSession();
        $held = $this->hold($session, '01712000009', '01J8ZK4V2Q3W5X6Y7Z8A9B0E09');
        $minutes = app(AdvancePaymentPolicy::class)->holdMinutes();

        $this->actingAsStaff(Role::Receptionist);

        // The hold is the session's only serial, so it is the board's first row.
        $this->board()
            ->assertJsonPath('sessions.0.serials.0.public_id', Serial::query()->findOrFail($held->serial_id)->public_id)
            ->assertJsonPath('sessions.0.serials.0.appointment.status', 'pending')
            ->assertJsonPath('sessions.0.serials.0.appointment.payment_status', 'unpaid')
            // the countdown the desk shows is the window the sweep actually uses
            ->assertJsonPath('sessions.0.serials.0.appointment.hold_expires_at', $held->created_at?->toImmutable()->addMinutes($minutes)->toIso8601ZuluString());

        // Paid: nothing is being held any more, so there is no deadline to count down.
        app(RecordCashPayment::class)->handle($held->refresh(), $held->fee_paisa, 'C-HOLD-'.$held->id, $this->staffActor());
        $this->assertSame(AppointmentStatus::Confirmed, $held->refresh()->status);
        $this->board()->assertJsonPath('sessions.0.serials.0.appointment.hold_expires_at', null);
    }

    public function test_a_swept_hold_stops_looking_like_a_booking_on_the_board(): void
    {
        $session = $this->advanceSession();
        $held = $this->hold($session, '01712000010', '01J8ZK4V2Q3W5X6Y7Z8A9B0E10');

        $this->actingAsStaff(Role::Receptionist);
        $this->assertNotNull($this->board()->json('sessions.0.serials.0.appointment.hold_expires_at'));

        $this->travel(app(AdvancePaymentPolicy::class)->holdMinutes() + 1)->minutes();
        $this->artisan('booking:expire-holds')->assertExitCode(0);

        $this->board()
            // the number is back with the clinic, and a cancelled hold has no deadline left to show
            ->assertJsonPath('sessions.0.serials.0.status', SerialStatus::Cancelled->value)
            ->assertJsonPath('sessions.0.serials.0.appointment.status', AppointmentStatus::Cancelled->value)
            ->assertJsonPath('sessions.0.serials.0.appointment.hold_expires_at', null);
    }

    /**
     * The JSON the desk board polls (panel.reception.board.data).
     *
     * @return TestResponse<Response>
     */
    private function board(): TestResponse
    {
        return $this->getJson(route('panel.reception.board.data', absolute: false))->assertOk();
    }

    public function test_it_is_a_no_op_on_a_clinic_with_nothing_to_expire(): void
    {
        $this->artisan('booking:expire-holds')->assertExitCode(0)->expectsOutputToContain('0 unpaid hold(s) released');
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_the_module_registers_its_own_per_tenant_schedule(): void
    {
        $schedule = new Schedule;
        $this->assertInstanceOf(RegistersSchedule::class, $schedule);

        $scheduler = app(Scheduler::class);
        $before = count($scheduler->events());
        $schedule->register($scheduler);
        $events = $scheduler->events();

        $this->assertCount($before + 1, $events);
        $this->assertStringContainsString('tenants:run booking:expire-holds', (string) end($events)->command);
    }

    public function test_the_command_refuses_to_run_outside_a_tenant(): void
    {
        Tenancy::end();
        $this->artisan('booking:expire-holds')->assertExitCode(1);
    }

    public function test_a_desk_check_in_keeps_a_hold_out_of_the_sweep(): void
    {
        $session = $this->advanceSession();
        $held = $this->hold($session, '01712000005', '01J8ZK4V2Q3W5X6Y7Z8A9B0E05');

        // The patient turned up: reception checks the serial in, which is a real transition and takes the
        // appointment out of `pending` — the sweep must not cancel a person standing at the desk.
        app(CheckInSerial::class)->handle(Serial::query()->findOrFail($held->serial_id), Actor::user((int) $this->actingAsStaff(Role::Receptionist)->id, 'receptionist'));

        $this->assertSame(AppointmentStatus::CheckedIn, $held->refresh()->status);

        $this->travel(31)->minutes();
        $this->artisan('booking:expire-holds')->assertExitCode(0)->expectsOutputToContain('0 unpaid hold(s) released');
        $this->assertSame(AppointmentStatus::CheckedIn, $held->refresh()->status);
    }
}
