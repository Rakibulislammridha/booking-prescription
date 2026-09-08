<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Billing\Actions\RecordCashPayment;
use App\Domain\Billing\Gateways\GatewayManager;
use App\Domain\Billing\Gateways\LogPaymentGateway;
use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\FeeRule;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Booking\Exceptions\AdvancePaymentUnavailable;
use App\Domain\Booking\Services\NoOnlinePayment;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/**
 * `doctor_profiles.advance_payment_required` — the "advance" leg of BRIEF §5.C's "payment (optional / advance /
 * full)". A self-service booking for such a doctor HOLDS its serial (`pending`, no `confirmed_at`) until the bill
 * is settled; a counter booking, a free follow-up and a tenant with no gateway are each unaffected or refused.
 */
final class AdvancePaymentTest extends TestCase
{
    use BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        app(Settings::class)->set('kiosk.otp_required', false);
    }

    /** @param  array<string, mixed>  $params */
    private function url(string $name, array $params = []): string
    {
        return route($name, $params, false);
    }

    /** A doctor whose online bookings must be paid before the serial counts (the panel switch). */
    private function advanceDoctor(): Doctor
    {
        $doctor = Doctor::factory()->complete()->create();
        $doctor->profile?->forceFill(['advance_payment_required' => true])->save();

        return $doctor->load('profile');
    }

    /** Credentials (the log driver) AND the tenant switch — BillingOnlinePaymentGateway needs both. */
    private function enableOnlinePayment(): void
    {
        config(['billing.gateways.driver' => 'log']);
        app(GatewayManager::class)->forget();
        app(Settings::class)->set(NoOnlinePayment::SETTING, true);
    }

    /** @return TestResponse<Response> */
    private function bookOnline(SessionInstance $session, string $mobile, string $ulid): TestResponse
    {
        return $this->post($this->url('site.booking.store'), [
            'session' => $session->public_id, 'channel' => 'online', 'mobile' => $mobile,
            'name' => 'Rahima Begum', 'sex' => 'f', 'age_years' => 40, 'client_event_id' => $ulid,
        ]);
    }

    public function test_an_online_booking_for_an_advance_payment_doctor_is_held_and_sent_to_checkout(): void
    {
        $this->enableOnlinePayment();
        $doctor = $this->advanceDoctor();
        $session = $this->openSession(10, 10, 5, $doctor);

        $this->get($this->url('site.booking.doctor', ['doctor' => $doctor->slug]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Booking/Doctor')->where('advance_payment_required', true)->where('online_payment_enabled', true));

        $response = $this->bookOnline($session, '01712345678', '01J8ZK4V2Q3W5X6Y7Z8A9B0D01');

        $appointment = Appointment::query()->where('session_instance_id', $session->id)->firstOrFail();
        $response->assertRedirect($this->url('site.billing.checkout', ['appointment' => $appointment->public_id]));

        // The serial is real and atomically allocated — only the appointment is held.
        $serial = Serial::query()->findOrFail($appointment->serial_id);
        $this->assertSame(SerialPool::Online, $serial->pool);
        $this->assertSame(SerialStatus::Booked, $serial->status);
        $this->assertSame($appointment->id, $serial->appointment_id);

        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertNull($appointment->confirmed_at, 'a held serial has not been confirmed to anybody');
        $this->assertSame(PaymentStatus::Unpaid, $appointment->payment_status);

        $this->get($this->url('site.booking.confirmed', ['appointment' => $appointment->public_id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Booking/Confirmed')
                ->where('held_for_payment', true)
                ->where('hold_minutes', 30)
                ->where('pay_at_counter', false)
                ->where('appointment.status', 'pending')
                ->where('checkout_url', route('site.billing.checkout', ['appointment' => $appointment->public_id])));
    }

    public function test_settling_the_invoice_online_confirms_the_held_serial(): void
    {
        $this->enableOnlinePayment();
        $session = $this->openSession(10, 10, 5, $this->advanceDoctor());
        $this->bookOnline($session, '01712345679', '01J8ZK4V2Q3W5X6Y7Z8A9B0D02');

        $appointment = Appointment::query()->where('session_instance_id', $session->id)->firstOrFail();
        $this->assertSame(AppointmentStatus::Pending, $appointment->status);

        // The real patient path: the checkout page issues the bill, "pay" starts the attempt, the gateway calls back.
        $this->get($this->url('site.billing.checkout', ['appointment' => $appointment->public_id]))->assertOk();
        $this->post($this->url('site.billing.checkout.start', ['appointment' => $appointment->public_id]), ['gateway' => 'sslcommerz'])->assertRedirect();

        $payment = Payment::query()->latest('id')->firstOrFail();
        $ref = (string) $payment->gateway_payment_ref;
        $this->postJson('/api/webhooks/payments/sslcommerz', ['ref' => $ref, 'txn' => 'ADV-1', 'status' => 'success', 'sig' => LogPaymentGateway::sign($ref, 'ADV-1', 'success')])
            ->assertOk()->assertJsonPath('status', 'recorded');

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertNotNull($appointment->confirmed_at, 'the money is what confirms the hold');
        $this->assertSame(PaymentStatus::Paid, $appointment->payment_status);
        $this->assertSame(SerialStatus::Booked, Serial::query()->findOrFail($appointment->serial_id)->status);

        $this->get($this->url('site.booking.confirmed', ['appointment' => $appointment->public_id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('held_for_payment', false)->where('checkout_url', null));
    }

    public function test_taking_the_advance_in_cash_at_the_counter_also_confirms_the_hold(): void
    {
        $this->enableOnlinePayment();
        $session = $this->openSession(10, 10, 5, $this->advanceDoctor());
        $this->bookOnline($session, '01712345680', '01J8ZK4V2Q3W5X6Y7Z8A9B0D03');

        $appointment = Appointment::query()->where('session_instance_id', $session->id)->firstOrFail();
        $this->assertSame(AppointmentStatus::Pending, $appointment->status);

        $this->actingAsStaff(Role::Receptionist);
        app(RecordCashPayment::class)->handle($appointment->refresh(), $appointment->fee_paisa, 'C-ADV-'.$appointment->id, $this->staffActor());

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status, 'a receptionist taking the cash is a legitimate confirmation');
        $this->assertNotNull($appointment->confirmed_at);
        $this->assertSame(PaymentStatus::Paid, $appointment->payment_status);
    }

    public function test_a_partial_payment_leaves_the_serial_held(): void
    {
        $this->enableOnlinePayment();
        $session = $this->openSession(10, 10, 5, $this->advanceDoctor());
        $this->bookOnline($session, '01712345681', '01J8ZK4V2Q3W5X6Y7Z8A9B0D04');

        $appointment = Appointment::query()->where('session_instance_id', $session->id)->firstOrFail();
        $this->actingAsStaff(Role::Receptionist);
        app(RecordCashPayment::class)->handle($appointment->refresh(), 100, 'C-PART-'.$appointment->id, $this->staffActor());

        $appointment->refresh();
        $this->assertSame(PaymentStatus::Partial, $appointment->payment_status);
        $this->assertSame(AppointmentStatus::Pending, $appointment->status, 'part of the advance is not the advance');
        $this->assertNull($appointment->confirmed_at);
    }

    public function test_without_a_gateway_the_booking_is_refused_before_any_serial_is_allocated(): void
    {
        $doctor = $this->advanceDoctor();                 // note: online payment is NOT enabled
        $session = $this->openSession(10, 10, 5, $doctor);
        $serialsBefore = Serial::query()->count();

        $this->get($this->url('site.booking.doctor', ['doctor' => $doctor->slug]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('advance_payment_required', true)->where('online_payment_enabled', false));

        $this->postJson($this->url('site.booking.store'), [
            'session' => $session->public_id, 'channel' => 'online', 'mobile' => '01712345682',
            'name' => 'Rahima Begum', 'sex' => 'f', 'age_years' => 40, 'client_event_id' => '01J8ZK4V2Q3W5X6Y7Z8A9B0D05',
        ])->assertStatus(422)->assertJsonPath('code', 'booking.advance_payment_unavailable');

        // The Inertia form surface gets the same refusal as every other booking exception: an errors bag.
        $this->bookOnline($session, '01712345683', '01J8ZK4V2Q3W5X6Y7Z8A9B0D06')->assertSessionHasErrors('domain');

        $this->assertSame($serialsBefore, Serial::query()->count(), 'no number may leave the pool for a booking that can never be paid');
        $this->assertSame(0, Appointment::query()->where('session_instance_id', $session->id)->count());

        $this->assertThrows(fn () => app(BookAppointment::class)->handle(
            new BookingRequest(channel: BookingChannel::Kiosk, mobile: '01712345684', name: 'Kiosk Patient', sessionPublicId: $session->public_id, otpVerified: true),
            new Actor(source: 'web'),
        ), AdvancePaymentUnavailable::class);
    }

    public function test_a_counter_booking_for_the_same_doctor_is_confirmed_as_usual(): void
    {
        $this->enableOnlinePayment();
        $this->actingAsStaff(Role::Receptionist);
        $session = $this->openSession(10, 10, 5, $this->advanceDoctor());

        foreach ([BookingChannel::Counter, BookingChannel::Walkin, BookingChannel::Phone] as $i => $channel) {
            $result = app(BookAppointment::class)->handle(
                new BookingRequest(channel: $channel, mobile: '0171100000'.$i, name: 'Desk Patient '.$i, sessionPublicId: $session->public_id),
                $this->staffActor(),
            );

            $this->assertSame(AppointmentStatus::Confirmed, $result->appointment->status, $channel->value.' is not a self-service channel');
            $this->assertNotNull($result->appointment->confirmed_at);
        }
    }

    public function test_a_zero_fee_free_follow_up_is_never_held(): void
    {
        $doctor = $this->advanceDoctor();                 // deliberately WITHOUT a gateway: a free visit must not be blocked either
        $doctor->profile?->forceFill(['free_followup_within_days' => 15, 'followup_within_days' => 30])->save();

        $patient = Patient::factory()->create(['mobile' => '+8801755555555']);
        $past = SessionInstance::factory()->on($this->today()->subDays(7))->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id]);
        Appointment::factory()->completed()->create([
            'patient_id' => $patient->id, 'session_instance_id' => $past->id, 'doctor_id' => $doctor->id,
            'branch_id' => $past->branch_id, 'scheduled_date' => $past->session_date->toDateString(),
        ]);

        $session = $this->openSession(10, 10, 5, $doctor);
        $result = app(BookAppointment::class)->handle(
            new BookingRequest(channel: BookingChannel::Online, patientPublicId: $patient->public_id, sessionPublicId: $session->public_id, otpVerified: true),
            new Actor(source: 'web'),
        );

        $this->assertSame(FeeRule::FollowupFree, $result->appointment->fee_rule);
        $this->assertSame(0, $result->appointment->fee_paisa);
        $this->assertSame(AppointmentStatus::Confirmed, $result->appointment->status, 'there is nothing to pay in advance');
        $this->assertNotNull($result->appointment->confirmed_at);
    }

    public function test_a_doctor_without_the_switch_is_untouched_even_with_a_gateway(): void
    {
        $this->enableOnlinePayment();
        $session = $this->openSession(10, 10, 5);         // plain doctor, advance_payment_required = false

        $this->bookOnline($session, '01712345685', '01J8ZK4V2Q3W5X6Y7Z8A9B0D07');

        $appointment = Appointment::query()->where('session_instance_id', $session->id)->firstOrFail();
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertNotNull($appointment->confirmed_at);
    }
}
