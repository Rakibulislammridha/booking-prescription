<?php

declare(strict_types=1);

namespace Tests\Feature\Telemedicine;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Patients\Enums\ConsentStatus;
use App\Domain\Patients\Enums\ConsentType;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Actions\EndCall;
use App\Domain\Telemedicine\Actions\JoinCall;
use App\Domain\Telemedicine\Actions\LeaveCall;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\SessionEndReason;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\PatientConsent;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Telemedicine\Concerns\TelemedicineFixtures;
use Tests\TestCase;

/**
 * BRIEF §5.K "records and money": join and leave times, duration and outcome on `telemedicine_sessions`, the
 * minutes metered to `usage_counters`, and an audit row for every join, leave and recording action (BRIEF §8).
 */
final class SessionRecordsTest extends TestCase
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
    }

    private function latestCall(): TelemedicineSession
    {
        return TelemedicineSession::query()->where('telemedicine_room_id', $this->room->id)->orderByDesc('id')->firstOrFail();
    }

    public function test_participants_carry_join_and_leave_times_and_the_device(): void
    {
        $this->startCall($this->room);
        $room = $this->room->refresh();

        app(JoinCall::class)->handle($room, ParticipantRole::Doctor, Actor::user(1), 'panel');
        app(JoinCall::class)->handle($room, ParticipantRole::Patient, Actor::system(), 'Android 12 / Chrome');

        $participants = $this->latestCall()->participants;
        $this->assertCount(2, $participants);
        $this->assertSame(['doctor', 'patient'], array_column($participants, 'role'));
        $this->assertSame('panel', $participants[0]['device']);
        $this->assertSame('Android 12 / Chrome', $participants[1]['device']);
        $this->assertNotNull($participants[0]['joined_at']);
        $this->assertNull($participants[0]['left_at']);

        app(LeaveCall::class)->handle($room, ParticipantRole::Patient, Actor::system());
        $this->assertNotNull($this->latestCall()->participants[1]['left_at']);
    }

    public function test_the_duration_and_outcome_are_recorded_when_the_call_ends(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->startCall($this->room);
        app(JoinCall::class)->handle($this->room->refresh(), ParticipantRole::Doctor, Actor::user(1), 'panel');

        $this->travelTo(now()->addSeconds(185));
        $ended = app(EndCall::class)->handle($this->room->refresh(), SessionEndReason::Completed, Actor::user(1));

        $this->assertSame(185, $ended->duration_seconds);
        $this->assertSame(SessionEndReason::Completed, $ended->end_reason);
        $this->assertNotNull($ended->ended_at);
        $this->assertNotNull($ended->participants[0]['left_at'], 'ending the call closes every open presence');
        $this->assertNotNull($ended->visit_id, 'the record points at the ordinary visit');
    }

    public function test_the_minutes_are_metered_to_the_tenant_usage_counter(): void
    {
        $before = $this->usage($this->tenant('a'), UsageMetric::TelemedicineMinutes);
        $this->travelTo(now()->startOfMinute());
        $this->startCall($this->room);

        $this->travelTo(now()->addSeconds(185));                 // 3 m 5 s
        app(EndCall::class)->handle($this->room->refresh(), SessionEndReason::Completed, Actor::user(1));

        $this->assertSame($before + 4, $this->usage($this->tenant('a'), UsageMetric::TelemedicineMinutes), 'rounded up, like every telecom counts');
    }

    public function test_a_zero_length_call_meters_nothing(): void
    {
        $before = $this->usage($this->tenant('a'), UsageMetric::TelemedicineMinutes);
        $this->travelTo(now()->startOfMinute());
        $this->startCall($this->room);
        app(EndCall::class)->handle($this->room->refresh(), SessionEndReason::NoShow, Actor::user(1));

        $this->assertSame($before, $this->usage($this->tenant('a'), UsageMetric::TelemedicineMinutes));
    }

    public function test_every_join_and_leave_writes_an_audit_row(): void
    {
        $this->startCall($this->room);
        $room = $this->room->refresh();

        app(JoinCall::class)->handle($room, ParticipantRole::Doctor, Actor::user(1), 'panel');
        $call = $this->latestCall();
        $this->assertAudited(AuditAction::CheckIn, $call, ['event' => 'join', 'role' => 'doctor']);

        app(LeaveCall::class)->handle($room, ParticipantRole::Doctor, Actor::user(1));
        $this->assertAudited(AuditAction::Update, $call, ['event' => 'leave', 'role' => 'doctor']);

        app(EndCall::class)->handle($room->refresh(), SessionEndReason::Completed, Actor::user(1));
        $this->assertAudited(AuditAction::Update, $call, ['event' => 'end', 'reason' => 'completed']);
    }

    public function test_the_audit_row_names_the_patient_so_it_reaches_their_timeline(): void
    {
        $this->startCall($this->room);
        app(JoinCall::class)->handle($this->room->refresh(), ParticipantRole::Doctor, Actor::user(1), 'panel');

        $row = AuditLog::query()
            ->where('auditable_type', TelemedicineSession::class)
            ->where('action', AuditAction::CheckIn->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($this->room->appointment->patient_id, $row->patient_id);
        $this->assertSame('telemedicine', $row->context['module']);
        $this->assertSame($this->room->room_name, $row->context['room']);
    }

    public function test_recording_needs_the_clinic_switch_and_the_patient_consent(): void
    {
        $this->startCall($this->room);
        $room = $this->room->refresh();

        // The clinic has recording off by default: refused, loudly.
        $this->postJson('/panel/telemedicine/'.$room->room_name.'/recording', ['on' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'telemedicine.recording_not_allowed');

        // Clinic switch on, but no consent row: still refused.
        $room->forceFill(['settings' => ['recording' => true, 'max_minutes' => 45]])->save();
        $this->postJson('/panel/telemedicine/'.$room->room_name.'/recording', ['on' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'telemedicine.recording_not_allowed');

        // With both, it is allowed — and audited as a `share`, because a recording is clinical data leaving the room.
        PatientConsent::factory()->create([
            'patient_id' => $room->appointment->patient_id,
            'type' => ConsentType::Telemedicine,
            'status' => ConsentStatus::Granted,
        ]);
        $this->postJson('/panel/telemedicine/'.$room->room_name.'/recording', ['on' => true])
            ->assertOk()
            ->assertJsonPath('recording.active', true);

        $this->assertAudited(AuditAction::Share, $this->latestCall(), ['event' => 'recording', 'state' => 'started']);
    }

    public function test_the_recording_path_is_encrypted_at_rest(): void
    {
        $this->startCall($this->room);
        $call = $this->latestCall();
        $call->forceFill(['recording_path' => 'tenants/9001/telemedicine/abc.mp4'])->save();

        $raw = (string) DB::table('telemedicine_sessions')->where('id', $call->id)->value('recording_path');

        $this->assertNotSame('tenants/9001/telemedicine/abc.mp4', $raw, 'SCHEMA §5.5 marks it ENC');
        $this->assertSame('tenants/9001/telemedicine/abc.mp4', $call->refresh()->recording_path);
    }
}
