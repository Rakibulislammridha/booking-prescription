<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Domain\Queue\Events\BoardUpdated;
use App\Domain\Queue\Events\CallNext;
use App\Domain\Queue\Events\DoctorArrived;
use App\Domain\Queue\Events\QueueStateUpdated;
use App\Domain\Queue\Events\SerialCalled;
use App\Domain\Queue\Events\SerialCalledPrivate;
use App\Domain\Queue\Events\SerialStatusChanged;
use App\Domain\Queue\Events\SessionCancelled;
use App\Domain\Queue\Events\SessionDelayed;
use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Queue\TenantChannel;
use App\Domain\Serials\Actions\CancelSession;
use App\Domain\Serials\Actions\DelaySession;
use App\Domain\Serials\Actions\StartSession;
use App\Models\Tenant\Patient;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Queue\Concerns\QueueFixtures;
use Tests\TestCase;

/** REALTIME.md §3 — the wire events, their channels and their payloads. */
#[Group('realtime')]
final class BroadcastingTest extends TestCase
{
    use QueueFixtures;

    /** @var array<int, class-string> */
    private const WIRE = [
        SerialCalled::class, SerialCalledPrivate::class, SerialStatusChanged::class, QueueStateUpdated::class,
        SessionDelayed::class, SessionCancelled::class, DoctorArrived::class, BoardUpdated::class, CallNext::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /** Private channels carry the Pusher `private-` prefix on the wire; the client subscribes with echo.private(name). */
    private static function priv(string $name): string
    {
        return 'private-'.$name;
    }

    public function test_serial_called_is_broadcast_now_on_queue_reception_doctor_display_with_a_public_payload_without_names(): void
    {
        Event::fake(self::WIRE);

        $doctor = $this->queueDoctor('dr-rahman', 'Room 3');
        $session = $this->queueSession($doctor);
        $patient = Patient::factory()->create(['name' => 'Rahima Begum']);
        $serial = $this->issue($session, $patient->id);
        $this->checkIn($serial);
        $called = $this->callNext($session->fresh());
        $this->assertNotNull($called);

        $tenant = (string) Tenancy::current()?->public_id;

        Event::assertDispatched(SerialCalled::class, function (SerialCalled $e) use ($tenant, $session, $called, $doctor): bool {
            $this->assertSame([TenantChannel::queueName($tenant, $session->public_id)], $e->channelNames());
            $this->assertSame('serial.called', $e->broadcastAs());
            $this->assertInstanceOf(ShouldBroadcastNow::class, $e);
            $this->assertInstanceOf(ShouldRescue::class, $e);

            $payload = $e->broadcastWith();
            $this->assertSame(1, $payload['v']);
            $this->assertSame($session->public_id, $payload['session']);
            $this->assertSame($called->display_code, $payload['serial']['code']);
            $this->assertSame($called->number, $payload['serial']['n']);
            $this->assertSame($called->position, $payload['serial']['pos']);
            $this->assertSame($called->display_code, $payload['now_serving']);
            $this->assertNull($payload['previous']);
            $this->assertSame($doctor->room_label, $payload['room']);
            $this->assertGreaterThan(0, $payload['version']);
            $this->assertArrayNotHasKey('patient', $payload, 'the public channel never carries a name');
            $this->assertStringNotContainsString('Rahima', (string) json_encode($payload));

            return true;
        });

        Event::assertDispatched(SerialCalledPrivate::class, function (SerialCalledPrivate $e) use ($tenant, $session, $doctor): bool {
            $this->assertSame([
                self::priv(TenantChannel::receptionName($tenant, $this->mainBranch()->public_id)),
                self::priv(TenantChannel::doctorName($tenant, $doctor->public_id)),
                self::priv(TenantChannel::displayName($tenant, $this->mainBranch()->public_id)),
            ], $e->channelNames());
            $this->assertSame('serial.called', $e->broadcastAs());
            $this->assertSame($session->public_id, $e->payload['session']);
            $this->assertSame('Rahima', $e->payload['patient']['first_name'], 'first name only, never the full name');
            $this->assertSame(['first_name', 'age', 'sex'], array_keys($e->payload['patient']));

            return true;
        });
    }

    public function test_call_next_goes_to_the_doctor_and_display_channels_only_and_carries_the_spoken_lines(): void
    {
        Event::fake(self::WIRE);

        $doctor = $this->queueDoctor('dr-rahman', 'Room 3');
        $session = $this->queueSession($doctor);
        $serial = $this->issue($session, Patient::factory()->create(['name' => 'Rahima Begum'])->id);
        $this->checkIn($serial);
        $this->callNext($session->fresh());

        $tenant = (string) Tenancy::current()?->public_id;

        Event::assertDispatched(CallNext::class, function (CallNext $e) use ($tenant, $doctor, $session): bool {
            $this->assertSame([
                self::priv(TenantChannel::doctorName($tenant, $doctor->public_id)),
                self::priv(TenantChannel::displayName($tenant, $this->mainBranch()->public_id)),
            ], $e->channelNames());
            $this->assertSame('call.next', $e->broadcastAs());
            $this->assertInstanceOf(ShouldBroadcastNow::class, $e);

            $payload = $e->broadcastWith();
            $this->assertSame($session->public_id, $payload['session']);
            $this->assertSame($doctor->public_id, $payload['doctor']);
            $this->assertSame('Room 3', $payload['room']);
            $this->assertFalse($payload['patient']['vitals_taken']);
            $this->assertSame('সিরিয়াল এ এক, রুম তিন', $payload['speak']['bn']);
            $this->assertSame('Serial A one, room three', $payload['speak']['en']);

            return true;
        });
    }

    public function test_serial_status_changed_is_queued_on_critical_and_carries_the_counts(): void
    {
        Event::fake(self::WIRE);

        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $serial = $this->issue($session);
        $this->checkIn($serial);

        $tenant = (string) Tenancy::current()?->public_id;

        Event::assertDispatched(SerialStatusChanged::class, function (SerialStatusChanged $e) use ($tenant, $session): bool {
            if ($e->payload['to'] !== 'checked_in') {
                return false;
            }

            $this->assertSame([
                TenantChannel::queueName($tenant, $session->public_id),
                self::priv(TenantChannel::receptionName($tenant, $this->mainBranch()->public_id)),
            ], $e->channelNames());
            $this->assertSame('serial.status_changed', $e->broadcastAs());
            $this->assertInstanceOf(ShouldBroadcast::class, $e);
            $this->assertSame('critical', $e->broadcastQueue, 'queued on critical, not broadcast now');
            $this->assertSame('booked', $e->payload['from']);
            $this->assertSame(1, $e->payload['counts']['checked_in']);

            return true;
        });
    }

    public function test_queue_state_updated_is_queued_unique_until_processing_and_reads_the_snapshot_at_send_time(): void
    {
        Event::fake(self::WIRE);

        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $this->issue($session);

        $tenant = (string) Tenancy::current()?->public_id;

        Event::assertDispatched(QueueStateUpdated::class, function (QueueStateUpdated $e) use ($tenant, $session): bool {
            $this->assertSame([
                TenantChannel::queueName($tenant, $session->public_id),
                self::priv(TenantChannel::displayName($tenant, $this->mainBranch()->public_id)),
            ], $e->channelNames());
            $this->assertSame('queue.state', $e->broadcastAs());
            $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $e);
            $this->assertSame("qs:{$session->public_id}", $e->uniqueId());
            $this->assertSame(1, $e->uniqueFor);
            $this->assertSame('critical', $e->broadcastQueue);

            return true;
        });

        // the payload is read from Redis when the job runs, not captured at dispatch time
        $event = Event::dispatched(QueueStateUpdated::class)->first()[0];
        $this->issue($session);
        $session->refresh();
        $this->assertSame($session->version, $event->broadcastWith()['state']['version']);
        $this->assertGreaterThan($event->state['version'], $event->broadcastWith()['state']['version']);
    }

    public function test_board_updated_is_coalesced_per_branch_and_rebuilt_at_send_time(): void
    {
        Event::fake(self::WIRE);

        $session = $this->queueSession();
        $this->issue($session);

        $branch = $this->mainBranch();
        $tenant = (string) Tenancy::current()?->public_id;

        Event::assertDispatched(BoardUpdated::class, function (BoardUpdated $e) use ($tenant, $branch): bool {
            $this->assertSame([self::priv(TenantChannel::receptionName($tenant, $branch->public_id))], $e->channelNames());
            $this->assertSame('board.updated', $e->broadcastAs());
            $this->assertSame("board:{$branch->public_id}", $e->uniqueId());
            $this->assertSame(1, $e->uniqueFor);

            $payload = $e->broadcastWith();
            $this->assertSame($branch->public_id, $payload['branch']);
            $this->assertNotEmpty($payload['sessions']);
            $this->assertSame(['online', 'counter', 'released', 'buffer'], array_keys($payload['sessions'][0]['remaining']));

            return true;
        });
    }

    public function test_session_delayed_and_cancelled_are_broadcast_now_on_queue_reception_and_display(): void
    {
        Event::fake(self::WIRE);

        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $tenant = (string) Tenancy::current()?->public_id;
        $expected = [
            TenantChannel::queueName($tenant, $session->public_id),
            self::priv(TenantChannel::receptionName($tenant, $this->mainBranch()->public_id)),
            self::priv(TenantChannel::displayName($tenant, $this->mainBranch()->public_id)),
        ];

        app(DelaySession::class)->handle($session, 40, $this->queueActor(), 'Doctor is in surgery');

        Event::assertDispatched(SessionDelayed::class, function (SessionDelayed $e) use ($expected): bool {
            $this->assertSame($expected, $e->channelNames());
            $this->assertSame('session.delayed', $e->broadcastAs());
            $this->assertInstanceOf(ShouldBroadcastNow::class, $e);
            $this->assertSame(40, $e->payload['delay_minutes']);
            $this->assertSame('Doctor is in surgery', $e->payload['message']);
            $this->assertMatchesRegularExpression('/\p{Bengali}/u', $e->payload['message_bn']);
            $this->assertNotNull($e->payload['expected_start_at']);

            return true;
        });

        app(CancelSession::class)->handle($session->fresh(), $this->queueActor(), 'doctor ill');

        Event::assertDispatched(SessionCancelled::class, function (SessionCancelled $e) use ($expected): bool {
            $this->assertSame($expected, $e->channelNames());
            $this->assertSame('session.cancelled', $e->broadcastAs());
            $this->assertInstanceOf(ShouldBroadcastNow::class, $e);
            $this->assertIsArray($e->payload['alternatives']);

            return true;
        });
    }

    public function test_doctor_arrived_is_broadcast_when_the_session_starts(): void
    {
        Event::fake(self::WIRE);

        $session = $this->queueSession();
        app(StartSession::class)->handle($session, $this->queueActor());

        Event::assertDispatched(DoctorArrived::class, function (DoctorArrived $e) use ($session): bool {
            $this->assertSame('doctor.arrived', $e->broadcastAs());
            $this->assertSame($session->public_id, $e->payload['session']);
            $this->assertNotNull($e->payload['actual_start_at']);
            $this->assertCount(3, $e->channelNames());

            return true;
        });
    }

    public function test_channel_names_are_tenant_prefixed_with_public_ids(): void
    {
        $tenant = Tenancy::current();
        $this->assertNotNull($tenant);
        $branch = $this->mainBranch();
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);

        $this->assertSame("tenant.{$tenant->public_id}.queue.{$session->public_id}", TenantChannel::queue($session)->name);
        $this->assertSame("private-tenant.{$tenant->public_id}.reception.{$branch->public_id}", TenantChannel::reception($branch)->name);
        $this->assertSame("private-tenant.{$tenant->public_id}.doctor.{$doctor->public_id}", TenantChannel::doctor($doctor)->name);
        $this->assertSame("private-tenant.{$tenant->public_id}.display.{$branch->public_id}", TenantChannel::display($branch)->name);
        $this->assertSame("tenant.{$tenant->public_id}.reception.{$branch->public_id}", TenantChannel::receptionName($tenant->public_id, $branch->public_id), 'the guard registers the unprefixed pattern');

        foreach ([$tenant->public_id, $session->public_id, $branch->public_id, $doctor->public_id] as $id) {
            $this->assertSame(26, strlen($id), 'channel segments are 26-char ULIDs, never bigints');
        }
    }

    public function test_a_broadcast_failure_is_rescued_and_the_call_still_succeeds(): void
    {
        Broadcast::extend('throwing', fn () => new class implements Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            /**
             * @param  array<int, mixed>  $channels
             * @param  array<string, mixed>  $payload
             */
            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new \RuntimeException('reverb is down');
            }
        });
        config([
            'broadcasting.connections.throwing' => ['driver' => 'throwing'],
            'broadcasting.default' => 'throwing',
            'queue.default' => 'null',   // queued broadcasts would otherwise run inline on the sync driver
        ]);

        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $serial = $this->issue($session);
        $this->checkIn($serial);

        $called = $this->callNext($session->fresh());

        $this->assertNotNull($called, 'an unreachable Reverb must not fail the call');
        $this->assertSame($called->id, $session->fresh()?->now_serving_serial_id);
        $this->assertNotNull(app(QueueStateRepository::class)->snapshot($session->fresh()), 'the poll fallback still carries the state');
    }
}
