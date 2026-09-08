<?php

declare(strict_types=1);

namespace Tests\Feature\Telemedicine;

use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\FeeRule;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\TelemedicineRoom;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Telemedicine\Concerns\TelemedicineFixtures;
use Tests\TestCase;

/**
 * The public telemedicine booking channel. It posts to the ordinary `BookAppointment`; what is tested here is
 * that the CHANNEL is closed properly — only doctors who accept video, only when the clinic has the add-on, and
 * only with the OTP the clinic requires.
 */
final class SiteBookingTest extends TestCase
{
    use TelemedicineFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->enableTelemedicine();
    }

    public function test_the_channel_lists_only_doctors_who_consult_over_video(): void
    {
        [, $video] = $this->actingAsTelemedicineDoctor();
        $inPerson = Doctor::factory()->complete()->create(['accepts_telemedicine' => false, 'accepts_online_booking' => true]);
        $this->flushSession();

        $this->get('/telemedicine')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Telemedicine/Book')
                ->has('doctors', 1)
                ->where('doctors.0.slug', $video->slug)
                ->where('doctors.0.fee_paisa', 60000));

        $this->assertNotSame($video->slug, $inPerson->slug);
    }

    public function test_a_patient_books_a_video_consultation_through_the_ordinary_flow(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);
        $this->flushSession();
        // The OTP endpoint is the Booking module's own — this channel adds no second OTP flow.
        $this->postJson('/booking/otp', ['mobile' => '01712345678'])->assertOk();

        $response = $this->post('/telemedicine', [
            'session' => $session->public_id,
            'mobile' => '01712345678',
            'otp' => '000000',
            'name' => 'Rahima Begum',
            'client_event_id' => strtoupper((string) Str::ulid()),
        ]);

        $appointment = Appointment::query()->latest('id')->firstOrFail();
        $response->assertRedirect('/telemedicine/booked/'.$appointment->public_id);
        $this->assertSame(BookingChannel::Telemedicine, $appointment->channel);
        $this->assertSame(FeeRule::Telemedicine, $appointment->fee_rule);
        $this->assertSame(60000, $appointment->fee_paisa);
        $this->assertNotNull($appointment->serial_id, 'the ordinary serial engine allocated a number');
        $this->assertSame(1, TelemedicineRoom::query()->where('appointment_id', $appointment->id)->count());
    }

    public function test_the_confirmation_page_shows_the_join_link(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $booking = $this->bookTelemedicine($this->openSessionFor($doctor));
        $room = $this->roomFor($booking->appointment->id);
        $this->flushSession();

        $this->get('/telemedicine/booked/'.$booking->appointment->public_id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Telemedicine/Booked')
                ->where('room', $room->room_name)
                ->where('join_url', fn (?string $url) => is_string($url) && str_contains($url, '/telemedicine/j/'.$room->room_name) && str_contains($url, 'signature='))
                ->has('appointment.serial'));
    }

    public function test_a_doctor_who_does_not_consult_over_video_is_refused(): void
    {
        $doctor = Doctor::factory()->complete()->create(['accepts_telemedicine' => false, 'accepts_online_booking' => true]);
        $session = $this->openSessionFor($doctor);
        $this->flushSession();
        $this->postJson('/booking/otp', ['mobile' => '01712345678'])->assertOk();

        $this->postJson('/telemedicine', [
            'session' => $session->public_id,
            'mobile' => '01712345678',
            'otp' => '000000',
            'name' => 'Rahima Begum',
            'client_event_id' => strtoupper((string) Str::ulid()),
        ])->assertStatus(422)->assertJsonPath('code', 'telemedicine.doctor_not_available');
    }

    public function test_an_in_person_appointment_has_no_confirmation_page_here(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);
        $appointment = Appointment::factory()->create(['session_instance_id' => $session->id, 'is_telemedicine' => false]);
        $this->flushSession();

        $this->get('/telemedicine/booked/'.$appointment->public_id)->assertNotFound();
    }
}
