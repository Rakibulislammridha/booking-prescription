<?php

declare(strict_types=1);

namespace Tests\Feature\Reception;

use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Reception\Enums\OfflineEventStatus;
use App\Domain\Serials\Actions\CancelSerial;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\OfflineEvent;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialEvent;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use Tests\Feature\Reception\Concerns\ReceptionFixtures;
use Tests\TestCase;

/**
 * The compounder boundary on the OFFLINE path (OFFLINE §7), which had none of it: SyncReplayer dispatched every
 * event straight into a handler and `ReplayContext::serialRef` resolves a non-local ref as "any serial in the
 * tenant", so a device batch could name a colleague's row and walk past every conjunct the policies had grown.
 *
 * The attacker in each test is the account the review named: ONE user holding receptionist AND compounder. The
 * receptionist half is what makes them an acceptable X-Actor-User (AuthenticateReceptionDevice admits no other
 * role) and gives them the desk permissions; the compounder half is what must restrict them anyway. Two doctors
 * throughout, because a scope that cannot tell "mine" from "theirs" is not a scope.
 *
 * Every assertion is about the WIRE and the DATABASE, never about a screen: a rejected event has to come back in
 * the results list with a code the client can store, and the row it named has to be untouched.
 */
final class OfflineReplayScopeTest extends TestCase
{
    use ReceptionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /** A doctor with no weekly template: nothing this test did not create gets materialised onto the board. */
    private function newDoctor(): Doctor
    {
        return Doctor::factory()->complete()->create();
    }

    /** The threat model's account: an accepted device actor (receptionist) who is also a compounder on `$mine`. */
    private function restricted(Doctor ...$mine): User
    {
        $user = $this->receptionist();
        $user->assignRole(Role::Compounder->value);
        $user->assignedDoctors()->sync(array_map(fn (Doctor $d) => $d->id, $mine));

        return $user;
    }

    private function bookedSerial(SessionInstance $session, string $mobile, string $name): Serial
    {
        $booked = app(BookAppointment::class)->handle(
            new BookingRequest(channel: BookingChannel::Counter, mobile: $mobile, name: $name, sessionPublicId: $session->public_id),
            $this->staffActor(),
        );

        return Serial::query()->findOrFail($booked->appointment->serial_id);
    }

    /**
     * The headline: the out-of-scope event is REJECTED (not silently dropped, not a 500 over the whole batch) and
     * the desk's own queued work in the same POST still lands.
     */
    public function test_an_out_of_scope_check_in_is_rejected_and_the_rest_of_the_batch_still_lands(): void
    {
        $mine = $this->newDoctor();
        $theirs = $this->newDoctor();
        $mySession = $this->openSession(doctor: $mine);
        $theirSession = $this->openSession(doctor: $theirs);

        $ours = $this->bookedSerial($mySession, '01710000501', 'My Patient');
        $hers = $this->bookedSerial($theirSession, '01710000502', 'Their Patient');

        $device = $this->device();
        $actor = $this->restricted($mine);

        $json = $this->sync($device, $actor, [
            $this->checkInEvent(1, $hers->public_id),
            $this->checkInEvent(2, $ours->public_id),
        ])->assertOk()->json();

        $this->assertSame('rejected', $json['results'][0]['status']);
        $this->assertSame('actor_not_permitted', $json['results'][0]['server_result']['rejection']);
        $this->assertNotSame('', (string) $json['results'][0]['server_result']['message']);

        $this->assertSame('accepted', $json['results'][1]['status']);

        $this->assertSame(SerialStatus::Booked, $hers->refresh()->status, 'the colleague’s patient was never marked arrived');
        $this->assertSame(SerialStatus::CheckedIn, $ours->refresh()->status);
    }

    /** The rejection is durable and attributable: the offline_events row is the record of who tried what. */
    public function test_the_rejected_event_is_recorded_against_the_actor_and_the_session(): void
    {
        $theirs = $this->newDoctor();
        $theirSession = $this->openSession(doctor: $theirs);
        $hers = $this->bookedSerial($theirSession, '01710000503', 'Their Patient');

        $device = $this->device();
        $actor = $this->restricted($this->newDoctor());
        $event = $this->checkInEvent(1, $hers->public_id);

        $this->sync($device, $actor, [$event])->assertOk();

        $row = OfflineEvent::query()->where('client_event_id', $event['client_event_id'])->firstOrFail();
        $this->assertSame(OfflineEventStatus::Rejected, $row->status);
        $this->assertNull($row->conflict_reason, 'a refusal is a rejection, not a conflict the desk can resolve');
        $this->assertSame($actor->id, $row->actor_user_id);
        $this->assertSame($theirSession->id, $row->session_instance_id);
    }

    /** Issuing a number in a chamber the actor may not touch is the exact thing the role must never do. */
    public function test_an_out_of_scope_issue_serial_is_rejected_and_writes_no_serial(): void
    {
        $theirs = $this->newDoctor();
        $theirSession = $this->openSession(doctor: $theirs);

        $device = $this->device();
        $block = $this->leaseFor($device, $theirSession);
        $patient = $this->patientWithMobile('+8801710000504', 'Walk In');
        $actor = $this->restricted($this->newDoctor());

        $event = $this->issueEvent(1, $theirSession, $block, $block->range_start, $patient->public_id);
        $json = $this->sync($device, $actor, [$event])->assertOk()->json();

        $this->assertSame('rejected', $json['results'][0]['status']);
        $this->assertSame('actor_not_permitted', $json['results'][0]['server_result']['rejection']);
        $this->assertFalse(Serial::query()->where('client_event_id', $event['client_event_id'])->exists());
    }

    /** Money is the same question. `collect` on the booking is the ability the desk's own fee button asks for. */
    public function test_an_out_of_scope_collect_cash_is_rejected_and_takes_no_money(): void
    {
        $theirs = $this->newDoctor();
        $theirSession = $this->openSession(doctor: $theirs);
        $hers = $this->bookedSerial($theirSession, '01710000505', 'Their Patient');

        $device = $this->device();
        $actor = $this->restricted($this->newDoctor());

        $json = $this->sync($device, $actor, [$this->cashEvent(1, $hers->public_id, 50000, 'R-501')])->assertOk()->json();

        $this->assertSame('rejected', $json['results'][0]['status']);
        $this->assertSame('actor_not_permitted', $json['results'][0]['server_result']['rejection']);
        $this->assertSame(PaymentStatus::Unpaid, Appointment::query()->findOrFail($hers->appointment_id)->payment_status);
    }

    /** Printing writes a `printed` serial_event into the doctor's audit trail, so it is scoped too. */
    public function test_an_out_of_scope_print_token_is_rejected(): void
    {
        $theirSession = $this->openSession(doctor: $this->newDoctor());
        $hers = $this->bookedSerial($theirSession, '01710000506', 'Their Patient');

        $device = $this->device();
        $actor = $this->restricted($this->newDoctor());

        $this->sync($device, $actor, [$this->printEvent(1, $hers->public_id)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.server_result.rejection', 'actor_not_permitted');

        $this->assertFalse(SerialEvent::query()->where('serial_id', $hers->id)->where('type', 'printed')->exists());
    }

    /** A void slip is a session-level audit row, and a session names a doctor. */
    public function test_an_out_of_scope_void_local_is_rejected(): void
    {
        $theirSession = $this->openSession(doctor: $this->newDoctor());
        $device = $this->device();
        $actor = $this->restricted($this->newDoctor());

        $this->sync($device, $actor, [$this->voidEvent(1, $theirSession, $this->ulid())])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.server_result.rejection', 'actor_not_permitted');

        $this->assertFalse(SerialEvent::query()->where('session_instance_id', $theirSession->id)->where('type', 'void_local')->exists());
    }

    /**
     * The other half of the boundary: it must not swallow the desk's legitimate work. A walk-in stub names no
     * doctor, so there is nothing to narrow by and the restricted actor registers patients as before.
     */
    public function test_register_patient_is_unscoped_and_still_replays(): void
    {
        $mine = $this->newDoctor();
        $mySession = $this->openSession(doctor: $mine);
        $device = $this->device();
        $block = $this->leaseFor($device, $mySession);
        $actor = $this->restricted($mine);

        $stub = $this->registerEvent(1, 'L9', '01710000507', 'New Walk In');
        $issue = $this->issueEvent(2, $mySession, $block, $block->range_start, 'local:L9', $stub['client_event_id']);

        $json = $this->sync($device, $actor, [$stub, $issue])->assertOk()->json();

        $this->assertSame('accepted', $json['results'][0]['status']);
        $this->assertSame('accepted', $json['results'][1]['status'], 'their own doctor’s chamber is theirs to issue in');
    }

    /**
     * A DECISION the actor may not make is a 403 that changes nothing. Rejecting the row instead would let one
     * out-of-scope click permanently destroy another receptionist's conflict card — the leak turned inside out.
     */
    public function test_a_resolution_the_actor_may_not_make_is_refused_and_leaves_the_card_open(): void
    {
        $theirSession = $this->openSession(doctor: $this->newDoctor());
        $hers = $this->bookedSerial($theirSession, '01710000508', 'Cancelled Patient');
        app(CancelSerial::class)->handle($hers, CancelReason::PatientRequest, $this->staffActor());

        $device = $this->device();
        $desk = $this->receptionist();
        $event = $this->checkInEvent(1, $hers->public_id);
        $this->sync($device, $desk, [$event])->assertOk()->assertJsonPath('results.0.conflict_reason', 'status_regression');

        $this->resolve($device, $this->restricted($this->newDoctor()), $event['client_event_id'], 'reinstate')
            ->assertStatus(403)
            ->assertJsonPath('code', 'reception.actor_not_permitted');

        $row = OfflineEvent::query()->where('client_event_id', $event['client_event_id'])->firstOrFail();
        $this->assertSame(OfflineEventStatus::Conflict, $row->status, 'the card is still there for someone who may take it');
        $this->assertSame(SerialStatus::Cancelled, $hers->refresh()->status);
    }

    /**
     * GET /sync/conflicts returns whole payloads, and a `register_patient` payload is a patient's name and mobile.
     * A restricted actor reads only what they queued; an unrestricted one still reads the whole device, because
     * finishing the cards the previous shift left open is the point of the endpoint.
     */
    public function test_the_conflicts_list_hides_another_actors_payloads_from_a_restricted_actor(): void
    {
        $device = $this->device();
        $desk = $this->receptionist();
        $this->patientWithMobile('+8801710000509', 'Rahima Begum');

        $stub = $this->registerEvent(1, 'L1', '01710000509', 'Rahima Begum');
        $this->sync($device, $desk, [$stub])->assertOk()->assertJsonPath('results.0.conflict_reason', 'duplicate_patient');

        $url = route('api.reception.sync.conflicts', [], false);

        $mine = $this->asDevice($device, $this->restricted($this->newDoctor()))->getJson($url)->assertOk()->json();
        $this->assertSame([], $mine['events']);

        $theirs = $this->asDevice($device, $desk)->getJson($url)->assertOk()->json();
        $this->assertCount(1, $theirs['events']);
        $this->assertSame('Rahima Begum', $theirs['events'][0]['payload']['name']);
    }

    /**
     * A leased block is direct control of a doctor's serial numbers — the one thing the product owner said a
     * compounder must never have — so leasing asks `issue` on the session, and the list the PWA caches narrows the
     * same way rather than handing out numbers the replay would only reject.
     */
    public function test_a_restricted_actor_can_lease_only_on_its_own_doctors_sessions(): void
    {
        $mine = $this->newDoctor();
        $mySession = $this->openSession(doctor: $mine);
        $theirSession = $this->openSession(doctor: $this->newDoctor());

        $device = $this->device();
        $theirBlock = $this->leaseFor($device, $theirSession);
        $actor = $this->restricted($mine);
        $lease = route('api.reception.blocks.lease', [], false);

        $this->asDevice($device, $actor)->postJson($lease, ['session' => $theirSession->public_id, 'size' => 5])->assertStatus(403);
        $this->asDevice($device, $actor)->postJson($lease, ['session' => $mySession->public_id, 'size' => 5])->assertStatus(201);

        $listed = $this->asDevice($device, $actor)->getJson(route('api.reception.blocks.index', [], false))->assertOk()->json();
        $this->assertNotContains($theirBlock->public_id, array_column($listed['blocks'], 'public_id'));
    }
}
