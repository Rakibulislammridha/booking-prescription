<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Billing\Actions\RecordCashPayment;
use App\Domain\Billing\Gateways\GatewayManager;
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
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Support\Scheduling\RegistersSchedule;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Scheduling\Schedule as Scheduler;
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
