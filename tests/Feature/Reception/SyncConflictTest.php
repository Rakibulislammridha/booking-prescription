<?php

declare(strict_types=1);

namespace Tests\Feature\Reception;

use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Reception\Actions\CollectFee;
use App\Domain\Serials\Actions\CancelSerial;
use App\Domain\Serials\Actions\CloseSession;
use App\Domain\Serials\Actions\MarkNoShow;
use App\Domain\Serials\Actions\RevokeBlock;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialReinstatedAfterCancel;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\OfflineEvent;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientRelation;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SerialEvent;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Reception\Concerns\ReceptionFixtures;
use Tests\TestCase;

/** OFFLINE §8: every conflict type, every resolution, and the non-conflicts of §8.6. */
final class SyncConflictTest extends TestCase
{
    use ReceptionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_duplicate_patient_link_and_family_member(): void
    {
        $device = $this->device();
        $actor = $this->receptionist();
        $owner = $this->patientWithMobile('+8801710000021', 'Rahima Begum');

        $stub = $this->registerEvent(1, 'L1', '01710000021', 'Rahima Begum');
        $json = $this->sync($device, $actor, [$stub])->assertOk()->json();
        $this->assertSame('conflict', $json['results'][0]['status']);
        $this->assertSame('duplicate_patient', $json['results'][0]['conflict_reason']);
        $this->assertSame($owner->public_id, $json['results'][0]['server_result']['candidates'][0]['public_id']);
        $this->assertSame('L1', $json['results'][0]['server_result']['stub']['localId']);

        $this->resolve($device, $actor, $stub['client_event_id'], 'reissue')->assertStatus(422)->assertJsonPath('code', 'reception.resolution_not_allowed');
        $this->resolve($device, $actor, $stub['client_event_id'], 'link_patient', ['patient' => $owner->public_id])->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('server_result.patient.linked', true);
        $this->assertSame(1, Patient::query()->where('mobile', '+8801710000021')->count());
        $row = OfflineEvent::query()->where('client_event_id', $stub['client_event_id'])->firstOrFail();
        $this->assertSame('link_patient', $row->resolution?->value);
        $this->assertSame($actor->id, $row->resolved_by_user_id);
        $this->assertSame($owner->public_id, $row->resolution_params['patient'] ?? null);
        $this->resolve($device, $actor, $stub['client_event_id'], 'link_patient', ['patient' => $owner->public_id])->assertStatus(404)->assertJsonPath('code', 'reception.conflict_not_found');

        $child = $this->registerEvent(2, 'L2', '01710000021', 'Karim', 'm', 8);
        $this->sync($device, $actor, [$child])->assertOk()->assertJsonPath('results.0.status', 'conflict');
        $this->resolve($device, $actor, $child['client_event_id'], 'family_member', ['holder_patient' => $owner->public_id])->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('server_result.patient.created', true);
        $dependent = Patient::query()->where('mobile', '+8801710000021')->where('name', 'Karim')->firstOrFail();
        $this->assertFalse($dependent->is_mobile_owner);
        $this->assertSame($owner->id, PatientRelation::query()->where('dependent_patient_id', $dependent->id)->value('primary_patient_id'));
    }

    public function test_serial_already_used_after_revocation_reissue_and_discard(): void
    {
        $device = $this->device();
        $actor = $this->receptionist();
        $session = $this->openSession(30, 0, 5);
        $block = $this->leaseFor($device, $session, 5);
        $patient = $this->patientWithMobile('+8801710000031');

        // the tablet was revoked while offline; the desk re-leased and issued A-001 to someone else
        app(RevokeBlock::class)->handle($block, $this->staffActor(), 'tablet lost');
        $desk = $this->allocate($session);
        $this->assertSame(1, $desk->number, 'the desk drained the revoked free-list first');

        $issue = $this->issueEvent(1, $session, $block, 1, $patient->public_id);
        $checkIn = $this->checkInEvent(2, 'local:'.$issue['client_event_id'], $issue['client_event_id']);
        $json = $this->sync($device, $actor, [$issue, $checkIn])->assertOk()->json();
        $this->assertSame(['conflict', 'pending'], array_column($json['results'], 'status'));
        $this->assertSame('serial_already_used', $json['results'][0]['conflict_reason']);
        $this->assertSame('A-001', $json['results'][0]['server_result']['taken_by']['display_code']);
        $this->assertSame('revoked', $json['results'][0]['server_result']['block_status']);
        $this->assertSame(2, $json['results'][0]['server_result']['suggested_next']);

        $resolved = $this->resolve($device, $actor, $issue['client_event_id'], 'reissue')->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('server_result.reissued', true)->json();
        $this->assertSame('A-002', $resolved['server_result']['serial']['display_code'], 'next counter number, free-list first');
        $serial = Serial::query()->where('client_event_id', $issue['client_event_id'])->firstOrFail();
        $this->assertSame('offline', $serial->source->value);
        $this->assertNull($serial->serial_block_id);
        $this->assertNotNull($serial->appointment_id);

        $this->sync($device, $actor, [$checkIn])->assertOk()->assertJsonPath('results.0.status', 'accepted')->assertJsonPath('results.0.server_result.serial.status', 'checked_in');

        // a second collision is discarded
        $issue2 = $this->issueEvent(3, $session, $block, 1, $patient->public_id);
        $this->sync($device, $actor, [$issue2])->assertOk()->assertJsonPath('results.0.status', 'conflict');
        $this->resolve($device, $actor, $issue2['client_event_id'], 'discard', ['reason' => 'patient left'])->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('server_result.rejection', 'discarded');
    }

    public function test_number_still_free_on_a_released_row_is_accepted_with_a_split(): void
    {
        $device = $this->device();
        $actor = $this->receptionist();
        $session = $this->openSession(30, 0, 5);
        $block = $this->leaseFor($device, $session, 10);   // [1..10]
        $patient = $this->patientWithMobile('+8801710000041');
        app(RevokeBlock::class)->handle($block, $this->staffActor());

        $issue = $this->issueEvent(1, $session, $block, 4, $patient->public_id);
        $json = $this->sync($device, $actor, [$issue])->assertOk()->json();
        $this->assertSame('accepted', $json['results'][0]['status']);
        $this->assertSame('block_released', $json['results'][0]['server_result']['warning']);
        $this->assertSame('A-004', $json['results'][0]['server_result']['serial']['display_code']);

        $rows = SerialBlock::query()->where('session_instance_id', $session->id)->orderBy('range_start')->get();
        $this->assertSame([[1, 3, 'released'], [4, 4, 'exhausted'], [5, 10, 'released']], $rows->map(fn (SerialBlock $b) => [$b->range_start, $b->range_end, $b->status->value])->all());
        $this->assertSame($device->id, $rows[1]->reception_device_id);
        $this->assertNotNull($rows[2]->revoked_at, 'the tail keeps the revoked marker');

        // the desk keeps draining what is left, lowest first, and 4 is never reissued
        $numbers = array_map(fn (Serial $s) => $s->number, $this->allocateMany($session, 9));
        $this->assertSame([1, 2, 3, 5, 6, 7, 8, 9, 10], $numbers);
    }

    public function test_session_closed_move_record_in_closed_and_discard(): void
    {
        $device = $this->device();
        $actor = $this->receptionist();
        $admin = $this->hospitalAdmin();
        $doctor = Doctor::factory()->complete()->create();
        $morning = $this->openSession(30, 0, 5, $doctor);
        $evening = SessionInstance::factory()->on($this->today(), 'B', '17:00', '21:00')->quotas(10, 5, 5)->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id]);
        $block = $this->leaseFor($device, $morning, 5);
        $p1 = $this->patientWithMobile('+8801710000051', 'One');
        $p2 = $this->patientWithMobile('+8801710000052', 'Two');
        $p3 = $this->patientWithMobile('+8801710000053', 'Three');

        app(CloseSession::class)->handle($morning, $this->staffActor(), 'end of day');
        $this->assertSame('released', $block->fresh()->status->value);

        $move = $this->issueEvent(1, $morning, $block, 1, $p1->public_id);
        $record = $this->issueEvent(2, $morning, $block, 2, $p2->public_id);
        $drop = $this->issueEvent(3, $morning, $block, 3, $p3->public_id);
        $json = $this->sync($device, $actor, [$move, $record, $drop])->assertOk()->json();
        $this->assertSame(['conflict', 'conflict', 'conflict'], array_column($json['results'], 'status'));
        $this->assertSame('session_closed', $json['results'][0]['conflict_reason']);
        $this->assertSame('closed', $json['results'][0]['server_result']['session_status']);
        $this->assertSame($evening->public_id, $json['results'][0]['server_result']['alternatives'][0]['public_id']);

        $moved = $this->resolve($device, $actor, $move['client_event_id'], 'move_to_session', ['session' => $evening->public_id])->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('server_result.moved', true)->json();
        $this->assertSame('B-001', $moved['server_result']['serial']['display_code']);
        $this->assertSame($evening->id, Serial::query()->where('client_event_id', $move['client_event_id'])->value('session_instance_id'));

        $this->resolve($device, $actor, $record['client_event_id'], 'record_in_closed')->assertForbidden()->assertJsonPath('code', 'reception.resolution_requires_admin');
        $this->resolve($device, $admin, $record['client_event_id'], 'record_in_closed')->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('server_result.recorded_post_close', true);
        $recorded = Serial::query()->where('client_event_id', $record['client_event_id'])->firstOrFail();
        $this->assertSame(SerialStatus::Completed, $recorded->status);
        $this->assertSame(2, $recorded->number);
        $this->assertSame($morning->id, $recorded->session_instance_id);
        $this->assertNotNull($recorded->completed_at);
        $this->assertSame(1, SerialEvent::query()->where('serial_id', $recorded->id)->where('type', SerialEventType::RecordedPostClose->value)->count());
        $this->assertSame('completed', Appointment::query()->where('serial_id', $recorded->id)->firstOrFail()->status->value);
        $this->assertSame(1, $morning->fresh()->completed_count);

        $this->resolve($device, $actor, $drop['client_event_id'], 'discard', ['reason' => 'went home'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame(2, Serial::query()->whereIn('client_event_id', [$move['client_event_id'], $record['client_event_id'], $drop['client_event_id']])->count());
    }

    public function test_status_regression_reinstate_emits_serial_reinstated_after_cancel(): void
    {
        Event::fake([SerialReinstatedAfterCancel::class]);
        $device = $this->device();
        $actor = $this->receptionist();
        $session = $this->openSession(30, 0, 5);
        $booked = app(BookAppointment::class)->handle(new BookingRequest(channel: BookingChannel::Counter, mobile: '01710000061', name: 'Cancelled Online', sessionPublicId: $session->public_id), $this->staffActor());
        app(CancelSerial::class)->handle($booked->serial, CancelReason::PatientRequest, new Actor(patientId: $booked->patient->id, source: 'web'));

        $checkIn = $this->checkInEvent(1, $booked->serial->public_id);
        $json = $this->sync($device, $actor, [$checkIn])->assertOk()->json();
        $this->assertSame('conflict', $json['results'][0]['status']);
        $this->assertSame('status_regression', $json['results'][0]['conflict_reason']);
        $this->assertSame('patient', $json['results'][0]['server_result']['cancelled_by']);
        $this->assertSame('patient_request', $json['results'][0]['server_result']['cancel_reason_code']);

        $this->resolve($device, $actor, $checkIn['client_event_id'], 'reinstate')->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('server_result.reinstated_after_cancel', true)->assertJsonPath('server_result.serial.status', 'checked_in');
        Event::assertDispatched(SerialReinstatedAfterCancel::class);
        $this->assertSame('checked_in', $booked->appointment->fresh()->status->value);
        $this->assertSame(1, SerialEvent::query()->where('serial_id', $booked->serial->id)->where('type', SerialEventType::ReinstatedAfterCancel->value)->count());

        // discard path
        $other = app(BookAppointment::class)->handle(new BookingRequest(channel: BookingChannel::Counter, mobile: '01710000062', name: 'Also Cancelled', sessionPublicId: $session->public_id), $this->staffActor());
        app(CancelSerial::class)->handle($other->serial, CancelReason::Other, $this->staffActor());
        $second = $this->checkInEvent(2, $other->serial->public_id);
        $this->sync($device, $actor, [$second])->assertOk()->assertJsonPath('results.0.status', 'conflict')->assertJsonPath('results.0.server_result.cancelled_by', 'staff');
        $this->resolve($device, $actor, $second['client_event_id'], 'discard')->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame('cancelled', $other->serial->fresh()->status->value);
    }

    public function test_already_paid_refund_cash_credit_and_discard_requires_admin(): void
    {
        $device = $this->device();
        $actor = $this->receptionist();
        $admin = $this->hospitalAdmin();
        $session = $this->openSession(30, 0, 5);
        $booked = app(BookAppointment::class)->handle(new BookingRequest(channel: BookingChannel::Counter, mobile: '01710000071', name: 'Paid Online', sessionPublicId: $session->public_id), $this->staffActor());
        app(CollectFee::class)->handle($booked->appointment, 80000, $this->staffActor());

        $cash = $this->cashEvent(1, $booked->serial->public_id, 50000, 'D1-000001');
        $json = $this->sync($device, $actor, [$cash])->assertOk()->json();
        $this->assertSame('conflict', $json['results'][0]['status']);
        $this->assertSame('already_paid', $json['results'][0]['conflict_reason']);
        $this->assertSame(50000, $json['results'][0]['server_result']['cash']['amount']);
        $this->assertSame('D1-000001', $json['results'][0]['server_result']['cash']['receipt_no']);

        $this->resolve($device, $actor, $cash['client_event_id'], 'refund_cash')->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('server_result.refund.status', 'refunded')->assertJsonPath('server_result.refund.amount_paisa', 50000);

        $credit = $this->cashEvent(2, $booked->serial->public_id, 20000, 'D1-000002');
        $this->sync($device, $actor, [$credit])->assertOk()->assertJsonPath('results.0.status', 'conflict');
        $this->resolve($device, $actor, $credit['client_event_id'], 'credit')->assertOk()->assertJsonPath('server_result.credit.status', 'credited');

        $discard = $this->cashEvent(3, $booked->serial->public_id, 10000, 'D1-000003');
        $this->sync($device, $actor, [$discard])->assertOk()->assertJsonPath('results.0.status', 'conflict');
        $this->resolve($device, $actor, $discard['client_event_id'], 'discard', ['reason' => 'test entry'])->assertForbidden();
        $this->resolve($device, $admin, $discard['client_event_id'], 'discard', ['reason' => 'test entry'])->assertOk()->assertJsonPath('status', 'rejected');
    }

    public function test_non_conflicts_are_accepted_silently(): void
    {
        $device = $this->device();
        $actor = $this->receptionist();
        $session = $this->openSession(30, 0, 5);
        $block = $this->leaseFor($device, $session, 5);
        $booked = app(BookAppointment::class)->handle(new BookingRequest(channel: BookingChannel::Counter, mobile: '01710000081', name: 'Twice Arrived', sessionPublicId: $session->public_id), $this->staffActor());
        $noShow = app(BookAppointment::class)->handle(new BookingRequest(channel: BookingChannel::Counter, mobile: '01710000082', name: 'No Show', sessionPublicId: $session->public_id), $this->staffActor());
        app(MarkNoShow::class)->handle($noShow->serial, $this->staffActor());

        $first = $this->checkInEvent(1, $booked->serial->public_id);
        $dup = $this->checkInEvent(2, $booked->serial->public_id);
        $reinstate = $this->checkInEvent(3, $noShow->serial->public_id);
        $print = $this->printEvent(4, $booked->serial->public_id);
        $void = $this->voidEvent(5, $session, '01J8ZK4V2Q3W5X6Y7Z8A9B0C8A');
        $cash = $this->cashEvent(6, $booked->serial->public_id, 80000, 'D1-000010');
        $cashAgain = $this->cashEvent(7, $booked->serial->public_id, 80000, 'D1-000010');
        $unknown = $this->checkInEvent(8, '01J8ZK4V2Q3W5X6Y7Z8A9B0C8B');

        $json = $this->sync($device, $actor, [$first, $dup, $reinstate, $print, $void, $cash, $cashAgain, $unknown])->assertOk()->json();
        $statuses = array_column($json['results'], 'status');
        $this->assertSame(['accepted', 'accepted', 'accepted', 'accepted', 'accepted', 'accepted', 'accepted', 'rejected'], $statuses);
        $this->assertTrue($json['results'][1]['server_result']['noop']);
        $this->assertTrue($json['results'][2]['server_result']['reinstated']);
        $this->assertSame('checked_in', $noShow->serial->fresh()->status->value);
        $this->assertSame('paid', $json['results'][5]['server_result']['payment']['payment_status']);
        $this->assertTrue($json['results'][6]['server_result']['noop'], 'the same receipt is not double-counted');
        $this->assertSame('serial_not_found', $json['results'][7]['server_result']['rejection']);
        $this->assertSame(1, SerialEvent::query()->where('serial_id', $booked->serial->id)->where('type', SerialEventType::Printed->value)->count());
        $this->assertSame(1, SerialEvent::query()->where('session_instance_id', $session->id)->whereNull('serial_id')->where('type', SerialEventType::VoidLocal->value)->count());
        $this->assertSame('paid', $booked->appointment->fresh()->payment_status->value);
        $this->assertSame(1, $block->next_number);
    }

    public function test_rejections_for_bad_blocks_and_numbers(): void
    {
        $device = $this->device();
        $stranger = $this->device();
        $actor = $this->receptionist();
        $session = $this->openSession(30, 0, 5);
        $block = $this->leaseFor($device, $session, 5);
        $foreign = $this->leaseFor($stranger, $session, 5);
        $patient = $this->patientWithMobile('+8801710000091');

        $notOwned = $this->issueEvent(1, $session, $foreign, 6, $patient->public_id);
        $outOfRange = $this->issueEvent(2, $session, $block, 99, $patient->public_id);
        $unknownBlock = $this->issueEvent(3, $session, $block, 1, $patient->public_id);
        $unknownBlock['payload']['blockId'] = '01J8ZK4V2Q3W5X6Y7Z8A9B0C7A';
        $unknownPatient = $this->issueEvent(4, $session, $block, 1, 'local:never');

        $json = $this->sync($device, $actor, [$notOwned, $outOfRange, $unknownBlock, $unknownPatient])->assertOk()->json();
        $this->assertSame(['rejected', 'rejected', 'rejected', 'rejected'], array_column($json['results'], 'status'));
        $this->assertSame(['block_not_owned', 'number_out_of_block_range', 'block_unknown', 'payload_invalid'], array_map(fn (array $r) => $r['server_result']['rejection'], $json['results']));
        $this->assertSame(0, Serial::query()->where('session_instance_id', $session->id)->count());
    }
}
