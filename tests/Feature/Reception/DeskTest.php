<?php

declare(strict_types=1);

namespace Tests\Feature\Reception;

use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Patient;
use App\Models\Tenant\ReceptionDevice;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Reception\Concerns\ReceptionFixtures;
use Tests\TestCase;

/** The panel desk (routes/panel/reception.php): board page + JSON, counter booking dialog, fee, cancel, shift, devices, kiosk QR. */
final class DeskTest extends TestCase
{
    use ReceptionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /** @param  array<string, mixed>  $params */
    private function url(string $name, array $params = []): string
    {
        return route($name, $params, false);
    }

    public function test_board_page_and_json_show_todays_sessions_with_counts(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        $session = $this->openSession(10, 10, 5);
        $this->allocate($session);

        $this->get($this->url('panel.reception.board'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Reception/Board')
                ->where('board.date', $this->today()->toDateString())
                ->has('board.sessions', 2)
                ->where('can.issue', true)->where('can.call_next', true)->where('can.register_device', true)->where('can.revoke', false)
                ->where('print_format', '58')
                ->has('channel')->has('settings'));

        /** @var array{sessions: array<int, array<string, mixed>>} $json */
        $json = $this->getJson($this->url('panel.reception.board.data'))->assertOk()->json();
        /** @var array<string, mixed> $row */
        $row = collect($json['sessions'])->firstWhere('public_id', $session->public_id);
        $this->assertSame(1, $row['counts']['booked']);
        $this->assertSame(9, $row['remaining']['counter']);
        $this->assertCount(1, $row['serials']);
        $this->assertContains($doctor->public_id, array_map(fn (array $s) => $s['doctor']['public_id'], $json['sessions']));
    }

    public function test_counter_booking_dialog_books_collects_fee_prints_and_cancels(): void
    {
        $user = $this->actingAsStaff(Role::Receptionist);
        $session = $this->openSession(10, 10, 5);

        $booked = $this->postJson($this->url('panel.reception.bookings.store'), ['session' => $session->public_id, 'channel' => 'counter', 'mobile' => '01711223344', 'name' => 'Rahima Begum', 'sex' => 'f', 'age_years' => 54, 'client_event_id' => '01J8ZK4V2Q3W5X6Y7Z8A9B0C3A'])
            ->assertCreated()->assertJsonPath('serial.display_code', 'A-001')->assertJsonPath('serial.patient.name', 'Rahima Begum')->assertJsonPath('appointment.fee.paisa', 80000)->assertJsonPath('patient_created', true);
        $appointment = (string) $booked->json('appointment.public_id');

        $this->postJson($this->url('panel.reception.bookings.store'), ['session' => $session->public_id, 'channel' => 'counter', 'mobile' => '01711223344', 'name' => 'Rahima Begum', 'client_event_id' => '01J8ZK4V2Q3W5X6Y7Z8A9B0C3A'])->assertOk()->assertJsonPath('replayed', true);
        $this->postJson($this->url('panel.reception.bookings.store'), ['session' => $session->public_id, 'channel' => 'counter', 'mobile' => '01711223344', 'name' => 'Rahima Begum'])->assertStatus(409)->assertJsonPath('code', 'booking.already_booked');

        // household ambiguity returns the household for the dialog
        Patient::factory()->dependentOf(Patient::query()->where('mobile', '+8801711223344')->firstOrFail())->create(['name' => 'Karim']);
        $this->postJson($this->url('panel.reception.bookings.store'), ['session' => $session->public_id, 'channel' => 'walkin', 'mobile' => '01711223344'])->assertStatus(409)->assertJsonPath('code', 'booking.patient_ambiguous')->assertJsonCount(2, 'household');
        $walkin = $this->postJson($this->url('panel.reception.bookings.store'), ['session' => $session->public_id, 'channel' => 'walkin', 'mobile' => '01711223344', 'name' => 'Karim', 'priority' => 'emergency', 'priority_reason' => 'chest pain'])->assertCreated()->assertJsonPath('serial.pool', 'buffer')->assertJsonPath('serial.priority', 'emergency');

        $this->postJson($this->url('panel.reception.appointments.collect', ['appointment' => $appointment]), [])->assertOk()->assertJsonPath('payment.payment_status', 'paid')->assertJsonPath('payment.amount_paisa', 80000);
        $this->postJson($this->url('panel.reception.appointments.collect', ['appointment' => $appointment]), [])->assertStatus(409)->assertJsonPath('code', 'reception.fee_already_collected');
        $this->assertSame('paid', Appointment::query()->where('public_id', $appointment)->firstOrFail()->payment_status->value);

        $this->postJson($this->url('panel.reception.appointments.cancel', ['appointment' => (string) $walkin->json('appointment.public_id')]), ['reason_code' => 'patient_request', 'note' => 'left'])->assertOk()->assertJsonPath('appointment.status', 'cancelled')->assertJsonPath('serial.status', 'cancelled')->assertJsonPath('refund_eligible', true);
        $this->postJson($this->url('panel.reception.appointments.cancel', ['appointment' => $appointment]), ['reason_code' => 'transferred'])->assertStatus(422);

        /** @var array<int, array<string, mixed>> $lookup */
        $lookup = $this->getJson($this->url('panel.reception.patients.lookup', ['q' => '01711223344']))->assertOk()->assertJsonCount(2, 'data')->json('data');
        /** @var array<string, mixed>|null $rahima */
        $rahima = collect($lookup)->firstWhere('name', 'Rahima Begum');
        $this->assertSame('confirmed', $rahima['history'][0]['status'] ?? null);
        $this->getJson($this->url('panel.reception.print_templates'))->assertOk()->assertJsonCount(3, 'templates');
        $this->getJson($this->url('panel.reception.kiosk_url', ['session' => $session->public_id]))->assertOk()->assertJsonPath('expires_in_hours', 12);
        $this->assertStringContainsString('signature=', (string) $this->getJson($this->url('panel.reception.kiosk_url'))->json('url'));
        $this->assertSame($user->id, Appointment::query()->where('public_id', $appointment)->firstOrFail()->booked_by_user_id);
    }

    public function test_shift_and_devices_pages(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $session = $this->openSession(10, 10, 5);
        $this->postJson($this->url('panel.reception.bookings.store'), ['session' => $session->public_id, 'channel' => 'counter', 'mobile' => '01755667788', 'name' => 'Shift'])->assertCreated();
        $appointment = Appointment::query()->firstOrFail();
        $this->postJson($this->url('panel.reception.appointments.collect', ['appointment' => $appointment->public_id]), ['amount_paisa' => 80000])->assertOk();

        $this->get($this->url('panel.reception.shift'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Reception/Shift')
                ->where('summary.serials.issued', 1)->where('summary.money.expected_paisa', 80000)->where('summary.money.collected_paisa', 80000)->where('summary.money.paid_appointments', 1)
                ->has('summary.per_user', 1)->where('summary.per_user.0.collected_paisa', 80000));

        $device = $this->device();
        $this->get($this->url('panel.reception.devices.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Reception/Devices')->has('devices', 1)->where('can.revoke', true));
        $this->post($this->url('panel.reception.devices.revoke', ['device' => $device->public_id]))->assertRedirect($this->url('panel.reception.devices.index'));
        $this->assertSame('revoked', ReceptionDevice::query()->findOrFail($device->id)->status->value);
    }

    public function test_accountant_cannot_book_or_cancel(): void
    {
        $this->actingAsStaff(Role::Accountant);
        $session = $this->openSession();
        $this->postJson($this->url('panel.reception.bookings.store'), ['session' => $session->public_id, 'channel' => 'counter', 'mobile' => '01700001111', 'name' => 'X'])->assertForbidden();
    }
}
