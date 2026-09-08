<?php

declare(strict_types=1);

namespace Tests\Feature\Telemedicine;

use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Actions\EndCall;
use App\Domain\Telemedicine\Actions\JoinCall;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\RoomStatus;
use App\Domain\Telemedicine\Enums\SessionEndReason;
use App\Domain\Telemedicine\Services\Jwt;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Telemedicine\Concerns\TelemedicineFixtures;
use Tests\TestCase;

/**
 * The waiting room: the same live queue a patient in the corridor sees, plus the states the corridor has no
 * equivalent of — the doctor is not here yet, the room is open, you may ask for a token now.
 */
final class WaitingRoomTest extends TestCase
{
    use TelemedicineFixtures;

    private TelemedicineRoom $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->enableTelemedicine();
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $booking = $this->bookTelemedicine($this->openSessionFor($doctor));
        $this->room = $this->roomFor($booking->appointment->id);
        $this->flushSession();
        $this->actingAsPatient($booking->patient);
    }

    public function test_the_waiting_room_carries_the_call_document_and_the_queue_handles(): void
    {
        $this->get('/telemedicine/room/'.$this->room->room_name)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Telemedicine/Room')
                ->where('telemedicine.status', 'scheduled')
                ->where('telemedicine.can_join', false)
                ->where('telemedicine.viewer', 'patient')
                ->where('telemedicine.presence.doctor', false)
                ->has('telemedicine.serial.code')
                ->has('telemedicine.doctor.slug')
                // The queue handles the client hands straight to the Queue module's own useQueueState().
                ->has('telemedicine.queue.tenant_public_id')
                ->has('telemedicine.queue.session_public_id')
                ->has('telemedicine.queue.doctor_slug')
                ->has('queue_state'));
    }

    public function test_a_patient_who_arrives_early_is_told_to_wait_and_gets_no_token(): void
    {
        $this->getJson('/telemedicine/room/'.$this->room->room_name.'/state')
            ->assertOk()
            ->assertJsonPath('status', 'scheduled')
            ->assertJsonPath('can_join', false);

        $this->postJson('/telemedicine/room/'.$this->room->room_name.'/token')
            ->assertStatus(409)
            ->assertJsonPath('code', 'telemedicine.room_not_joinable');
    }

    public function test_once_the_doctor_starts_the_patient_may_join_and_gets_a_patient_scoped_token(): void
    {
        $this->startCall($this->room);

        $this->getJson('/telemedicine/room/'.$this->room->room_name.'/state')
            ->assertOk()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('can_join', true);

        $response = $this->postJson('/telemedicine/room/'.$this->room->room_name.'/token')->assertOk();

        $response->assertJsonPath('role', 'patient');
        $response->assertJsonPath('room', $this->room->room_name);
        $claims = Jwt::decode((string) $response->json('token'), (string) config('app.key'));
        $this->assertIsArray($claims);
        $this->assertFalse($claims['video']['roomAdmin']);
        $this->assertFalse($claims['video']['roomRecord']);
        $this->assertTrue($claims['video']['canPublish']);
        $this->assertSame($this->room->room_name, $claims['video']['room']);
    }

    public function test_the_presence_flag_is_what_tells_the_patient_the_doctor_arrived(): void
    {
        $this->startCall($this->room);
        $call = TelemedicineSession::query()->where('telemedicine_room_id', $this->room->id)->firstOrFail();
        $this->assertFalse($call->hasJoined(ParticipantRole::Doctor));

        app(JoinCall::class)->handle($this->room->refresh(), ParticipantRole::Doctor, Actor::user(1), 'panel');

        $this->getJson('/telemedicine/room/'.$this->room->room_name.'/state')
            ->assertOk()
            ->assertJsonPath('presence.doctor', true)
            ->assertJsonPath('presence.patient', false);
    }

    public function test_a_dropped_patient_can_rejoin_the_same_call(): void
    {
        $this->startCall($this->room);
        $room = $this->room->refresh();

        $this->postJson('/telemedicine/room/'.$room->room_name.'/token')->assertOk();
        $this->postJson('/telemedicine/room/'.$room->room_name.'/leave')->assertOk()->assertJsonPath('presence.patient', false);
        $this->postJson('/telemedicine/room/'.$room->room_name.'/token')->assertOk()->assertJsonPath('role', 'patient');

        $call = TelemedicineSession::query()->where('telemedicine_room_id', $room->id)->firstOrFail();
        $entries = array_values(array_filter($call->participants, fn (array $p) => $p['role'] === 'patient'));
        $this->assertCount(2, $entries, 'a rejoin appends a second presence entry rather than losing the first');
        $this->assertNotNull($entries[0]['left_at']);
        $this->assertNull($entries[1]['left_at']);
    }

    public function test_a_finished_consultation_closes_the_waiting_room(): void
    {
        $this->startCall($this->room);
        app(EndCall::class)->handle($this->room->refresh(), SessionEndReason::Completed, Actor::system());

        $this->getJson('/telemedicine/room/'.$this->room->room_name.'/state')
            ->assertOk()
            ->assertJsonPath('status', RoomStatus::Ended->value)
            ->assertJsonPath('can_join', false);

        $this->postJson('/telemedicine/room/'.$this->room->room_name.'/token')->assertStatus(409);
    }

    public function test_the_patient_can_report_call_quality(): void
    {
        $this->startCall($this->room);
        $this->postJson('/telemedicine/room/'.$this->room->room_name.'/token')->assertOk();

        $this->postJson('/telemedicine/room/'.$this->room->room_name.'/quality', ['packet_loss_pct' => 4.5, 'rtt_ms' => 320, 'avg_bitrate_kbps' => 240])
            ->assertOk();

        $call = TelemedicineSession::query()->where('telemedicine_room_id', $this->room->id)->firstOrFail();
        $this->assertSame(4.5, $call->quality['packet_loss_pct']);
        $this->assertSame(320, $call->quality['rtt_ms']);
        $this->assertSame(240, $call->quality['avg_bitrate_kbps']);
    }

    public function test_absurd_quality_values_are_clamped_rather_than_stored(): void
    {
        $this->startCall($this->room);

        $this->postJson('/telemedicine/room/'.$this->room->room_name.'/quality', ['packet_loss_pct' => 5000])
            ->assertStatus(422);
    }
}
