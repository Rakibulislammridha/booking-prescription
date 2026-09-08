<?php

declare(strict_types=1);

namespace Tests\Feature\Telemedicine;

use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\FeeRule;
use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Prescription\Enums\VisitType;
use App\Domain\Serials\Actions\CancelSerial;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Enums\RoomStatus;
use App\Domain\Telemedicine\Enums\SessionEndReason;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Serial;
use App\Models\Tenant\TelemedicineSession;
use App\Models\Tenant\Visit;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Prescription\PrescriptionTestHelpers;
use Tests\Feature\Telemedicine\Concerns\TelemedicineFixtures;
use Tests\TestCase;

/**
 * BRIEF §5.K's one hard constraint: a video consultation ends in the SAME prescription flow as an in-person
 * visit. These tests walk booking → serial → call → visit → writer → issued prescription and assert, line by
 * line, that the only difference from a counter visit is the channel.
 */
final class TelemedicineFlowTest extends TestCase
{
    use PrescriptionTestHelpers, TelemedicineFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->enableTelemedicine();
    }

    public function test_booking_a_telemedicine_appointment_uses_the_ordinary_booking_flow(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);

        $result = $this->bookTelemedicine($session);

        // …the ordinary serial engine: an online-pool serial with a display code, atomically allocated.
        $this->assertSame(SerialPool::Online, $result->serial->pool);
        $this->assertSame(SerialSource::Online, $result->serial->source);
        $this->assertSame(SerialStatus::Booked, $result->serial->status);
        $this->assertNotSame('', $result->serial->display_code);

        // …the ordinary appointment, with the fee FeeResolver already knew how to compute for this channel.
        $this->assertSame(BookingChannel::Telemedicine, $result->appointment->channel);
        $this->assertTrue($result->appointment->is_telemedicine);
        $this->assertSame(FeeRule::Telemedicine, $result->appointment->fee_rule);
        $this->assertSame(60000, $result->appointment->fee_paisa, 'doctor_profiles.telemedicine_fee_paisa');
        $this->assertSame($result->serial->id, $result->appointment->serial_id);

        // …plus the one thing this module adds: a scheduled room with an unguessable name.
        $room = $this->roomFor($result->appointment->id);
        $this->assertSame(RoomStatus::Scheduled, $room->status);
        $this->assertMatchesRegularExpression('/^t9001-[0-9a-hjkmnp-tv-z]{26}$/', $room->room_name);
        $this->assertNotNull($room->patient_join_url_expires_at);
    }

    public function test_starting_the_call_drives_the_ordinary_serial_and_visit_lifecycle(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);
        $booking = $this->bookTelemedicine($session);
        $room = $this->roomFor($booking->appointment->id);

        $call = $this->startCall($room);

        $serial = Serial::query()->findOrFail($booking->serial->id);
        $this->assertSame(SerialStatus::InConsultation, $serial->status, 'the video call calls the serial like the chamber does');
        $this->assertNotNull($serial->called_at);
        $this->assertNotNull($serial->consultation_started_at, 'StartConsultation stamped it');
        $this->assertSame($serial->id, $session->refresh()->now_serving_serial_id);

        // The visit is the ORDINARY one, created by Prescription's own StartVisit listener on SerialCalled.
        $visit = Visit::query()->where('serial_id', $serial->id)->firstOrFail();
        $this->assertSame(VisitType::Telemedicine, $visit->type);
        $this->assertSame($booking->appointment->id, $visit->appointment_id);
        $this->assertSame($visit->id, $call->visit_id);
        $this->assertSame(RoomStatus::Open, $room->refresh()->status);
        $this->assertNotNull($room->opened_at);
    }

    public function test_the_doctor_lands_in_the_ordinary_prescription_writer(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);
        $booking = $this->bookTelemedicine($session);
        $room = $this->roomFor($booking->appointment->id);
        $this->startCall($room);
        $visit = Visit::query()->where('serial_id', $booking->serial->id)->firstOrFail();

        // 1. The ordinary writer route opens this visit — there is no telemedicine writer route.
        $this->get('/panel/visits/'.$visit->public_id.'/prescribe')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Prescription/Writer')->where('visit.id', $visit->public_id));

        // 2. The in-call console composes with it: same payload shape, rendered beside the video rail.
        $this->get('/panel/telemedicine/'.$room->room_name)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Telemedicine/Console')
                ->where('telemedicine.room', $room->room_name)
                ->where('can_consult', true)
                ->has('writer.visit')
                ->has('writer.patient')
                ->has('writer.quick_pick')
                ->where('writer.visit.id', $visit->public_id));
    }

    public function test_the_issued_prescription_is_indistinguishable_from_an_in_person_one(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);
        $booking = $this->bookTelemedicine($session);
        $room = $this->roomFor($booking->appointment->id);
        $this->startCall($room);
        $visit = Visit::query()->where('serial_id', $booking->serial->id)->firstOrFail();

        $draft = $this->draftFor($visit, $doctor);
        $this->patchJson('/panel/prescriptions/'.$draft->public_id.'/draft', [
            'language' => 'both',
            'items' => $this->itemsPayload([['slug' => 'paracetamol', 'shorthand' => '1+0+1 5d af', 'label' => '500 mg']]),
            'investigations' => [], 'advice' => [], 'referrals' => [],
        ])->assertOk();

        $rx = $this->issued($draft->fresh());

        $this->assertSame(PrescriptionStatus::Issued, $rx->status);
        $this->assertSame($visit->id, $rx->visit_id);
        $this->assertNotNull($rx->issued_at);
        $this->assertNotNull($rx->verification_code, 'the same QR-verifiable document a counter patient gets');
        $this->assertSame(1, $rx->version);
        $this->assertCount(1, $rx->items);
        $this->assertNotSame([], $rx->snapshot->toArray());

        // The ONLY telemedicine trace on the clinical record is the channel of the visit it belongs to.
        $columns = array_keys(Prescription::query()->whereKey($rx->id)->firstOrFail()->getAttributes());
        $this->assertSame([], array_values(array_filter($columns, fn (string $c) => str_contains($c, 'telemedicine'))));
        $this->assertSame(VisitType::Telemedicine, $visit->refresh()->type);
        $this->assertSame(BookingChannel::Telemedicine, $booking->appointment->refresh()->channel);
    }

    public function test_issuing_the_prescription_completes_the_serial_exactly_as_in_person(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);
        $booking = $this->bookTelemedicine($session);
        $room = $this->roomFor($booking->appointment->id);
        $this->startCall($room);
        $visit = Visit::query()->where('serial_id', $booking->serial->id)->firstOrFail();

        $draft = $this->draftFor($visit, $doctor);
        $this->patchJson('/panel/prescriptions/'.$draft->public_id.'/draft', [
            'language' => 'both',
            'items' => $this->itemsPayload([['slug' => 'paracetamol', 'shorthand' => '1+0+1 5d af', 'label' => '500 mg']]),
            'investigations' => [], 'advice' => [], 'referrals' => [],
        ])->assertOk();
        $this->issued($draft->fresh());

        $this->assertSame(SerialStatus::Completed, Serial::query()->findOrFail($booking->serial->id)->status);

        // Ending the call afterwards is then a no-op on the serial, not a second transition.
        $this->post('/panel/telemedicine/'.$room->room_name.'/end', ['reason' => 'completed'])->assertRedirect('/panel/telemedicine');
        $this->assertSame(SerialStatus::Completed, Serial::query()->findOrFail($booking->serial->id)->status);
        $this->assertSame(RoomStatus::Ended, $room->refresh()->status);
    }

    public function test_ending_the_call_completes_the_serial_in_one_action(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);
        $booking = $this->bookTelemedicine($session);
        $room = $this->roomFor($booking->appointment->id);
        $this->startCall($room);

        $this->post('/panel/telemedicine/'.$room->room_name.'/end', ['reason' => 'completed'])
            ->assertRedirect('/panel/telemedicine');

        $serial = Serial::query()->findOrFail($booking->serial->id);
        $this->assertSame(SerialStatus::Completed, $serial->status);
        $this->assertNotNull($serial->completed_at);

        $call = TelemedicineSession::query()->where('telemedicine_room_id', $room->id)->firstOrFail();
        $this->assertSame(SessionEndReason::Completed, $call->end_reason);
        $this->assertNotNull($call->ended_at);
        $this->assertSame(RoomStatus::Ended, $room->refresh()->status);
    }

    public function test_a_dropped_call_leaves_the_room_open_so_the_doctor_can_rejoin(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);
        $booking = $this->bookTelemedicine($session);
        $room = $this->roomFor($booking->appointment->id);
        $first = $this->startCall($room);

        $this->post('/panel/telemedicine/'.$room->room_name.'/end', ['reason' => 'dropped'])->assertRedirect();

        $this->assertSame(RoomStatus::Open, $room->refresh()->status, 'a drop is not the end of the consultation');
        $this->assertSame(SerialStatus::InConsultation, Serial::query()->findOrFail($booking->serial->id)->status);

        // A reconnect opens a NEW session row (SCHEMA §3.8) and keeps the same visit.
        $second = $this->startCall($room->refresh());
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->visit_id, $second->visit_id);
        $this->assertSame(2, TelemedicineSession::query()->where('telemedicine_room_id', $room->id)->count());
    }

    public function test_a_second_start_while_a_call_is_live_returns_the_same_session(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);
        $booking = $this->bookTelemedicine($session);
        $room = $this->roomFor($booking->appointment->id);

        $first = $this->startCall($room);
        $second = $this->startCall($room->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TelemedicineSession::query()->where('telemedicine_room_id', $room->id)->count());
    }

    public function test_a_cancelled_serial_makes_the_room_and_its_link_inert(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $session = $this->openSessionFor($doctor);
        $booking = $this->bookTelemedicine($session);
        $room = $this->roomFor($booking->appointment->id);

        app(CancelSerial::class)->handle(
            $booking->serial,
            CancelReason::PatientRequest,
            Actor::system(),
        );

        $this->assertSame(RoomStatus::Cancelled, $room->refresh()->status);
    }
}
