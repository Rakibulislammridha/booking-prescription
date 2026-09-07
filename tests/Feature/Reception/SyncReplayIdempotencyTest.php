<?php

declare(strict_types=1);

namespace Tests\Feature\Reception;

use App\Domain\Reception\Handlers\CheckInHandler;
use App\Domain\Reception\Sync\ReplayContext;
use App\Domain\Reception\Sync\ReplayHandler;
use App\Domain\Reception\Sync\ReplayOutcome;
use App\Domain\Reception\Sync\Resolution;
use App\Models\Tenant\OfflineEvent;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;
use RuntimeException;
use Tests\Feature\Reception\Concerns\ReceptionFixtures;
use Tests\TestCase;

/** OFFLINE §7.2 exactly-once: same batch twice, a crash mid-batch then retry, unordered / oversized batches, dependency ordering. */
final class SyncReplayIdempotencyTest extends TestCase
{
    use ReceptionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /** @return array<int, array<string, mixed>> register + issue + check_in for two patients from the block */
    private function log(SessionInstance $session, SerialBlock $block): array
    {
        $r1 = $this->registerEvent(1, 'L1', '01710000001', 'Patient One');
        $i1 = $this->issueEvent(2, $session, $block, $block->next_number, 'local:L1', $r1['client_event_id']);
        $c1 = $this->checkInEvent(3, 'local:'.$i1['client_event_id'], $i1['client_event_id']);
        $r2 = $this->registerEvent(4, 'L2', '01710000002', 'Patient Two');
        $i2 = $this->issueEvent(5, $session, $block, $block->next_number + 1, 'local:L2', $r2['client_event_id']);
        $c2 = $this->checkInEvent(6, 'local:'.$i2['client_event_id'], $i2['client_event_id']);

        return [$r1, $i1, $c1, $r2, $i2, $c2];
    }

    public function test_same_batch_twice_returns_identical_results_and_creates_no_duplicates(): void
    {
        $device = $this->device();
        $actor = $this->receptionist();
        $session = $this->openSession(30, 0, 5);
        $block = $this->leaseFor($device, $session, 10);
        $events = $this->log($session, $block);

        $first = $this->sync($device, $actor, $events)->assertOk()->json();
        $this->assertSame(['accepted', 'accepted', 'accepted', 'accepted', 'accepted', 'accepted'], array_column($first['results'], 'status'));
        $this->assertTrue($first['bootstrap_stale']);
        $this->assertSame('A-001', $first['results'][1]['server_result']['serial']['display_code']);
        $this->assertSame('checked_in', $first['results'][2]['server_result']['serial']['status']);

        $second = $this->sync($device, $actor, $events)->assertOk()->json();
        $this->assertSame($first['results'], $second['results'], 'stored server_result returned verbatim');
        $this->assertFalse($second['bootstrap_stale']);

        $this->assertSame(2, Serial::query()->where('session_instance_id', $session->id)->count());
        $this->assertSame(2, Patient::query()->whereIn('mobile', ['+8801710000001', '+8801710000002'])->count());
        $this->assertSame(6, OfflineEvent::query()->where('reception_device_id', $device->id)->count());
        $this->assertSame(3, $block->fresh()->next_number);
        $this->assertSame(1, OfflineEvent::query()->where('client_event_id', $events[1]['client_event_id'])->value('attempts'), 'a final row is not re-processed');
    }

    public function test_partial_failure_mid_batch_then_retry_completes_exactly_once(): void
    {
        $device = $this->device();
        $actor = $this->receptionist();
        $session = $this->openSession(30, 0, 5);
        $block = $this->leaseFor($device, $session, 10);
        $events = $this->log($session, $block);

        // event 3 (the first check_in) dies once; the row stays pending and is processed again on retry
        CrashOnceCheckIn::$calls = 0;
        $this->app->bind(CheckInHandler::class, fn ($app) => new CrashOnceCheckIn($app->build(CheckInHandler::class)));

        $first = $this->sync($device, $actor, $events)->assertOk()->json();
        $this->assertSame(['accepted', 'accepted', 'pending', 'accepted', 'accepted', 'accepted'], array_column($first['results'], 'status'));

        $retry = $this->sync($device, $actor, $events)->assertOk()->json();
        $this->assertSame(['accepted', 'accepted', 'accepted', 'accepted', 'accepted', 'accepted'], array_column($retry['results'], 'status'));
        $this->assertSame(2, Serial::query()->where('session_instance_id', $session->id)->count());
        $this->assertSame(2, Serial::query()->where('session_instance_id', $session->id)->where('status', 'checked_in')->count());
        $this->assertSame(2, OfflineEvent::query()->where('client_event_id', $events[2]['client_event_id'])->value('attempts'));
    }

    public function test_unordered_and_oversized_batches_are_422(): void
    {
        $device = $this->device();
        $actor = $this->receptionist();
        $session = $this->openSession(30, 0, 5);
        $block = $this->leaseFor($device, $session, 10);
        $events = $this->log($session, $block);

        $this->sync($device, $actor, [$events[1], $events[0]])->assertStatus(422)->assertJsonPath('code', 'reception.sync_batch_invalid');
        $this->assertSame(0, OfflineEvent::query()->count(), 'nothing is stored from a refused batch');

        $big = [];
        for ($i = 1; $i <= 201; $i++) {
            $big[] = $this->printEvent($i, 'local:x');
        }
        $this->sync($device, $actor, $big)->assertStatus(422);
        $this->sync($device, $actor, [['client_event_id' => 'not-a-ulid', 'sequence_no' => 1, 'type' => 'print_token', 'client_occurred_at' => now()->toIso8601String(), 'payload' => []]])->assertStatus(422);
    }

    public function test_dependency_on_a_conflicted_or_missing_event_returns_pending_until_resolved(): void
    {
        $device = $this->device();
        $actor = $this->receptionist();
        $session = $this->openSession(30, 0, 5);
        $block = $this->leaseFor($device, $session, 10);
        $this->patientWithMobile('+8801710000009', 'Existing Person');

        $register = $this->registerEvent(1, 'L9', '01710000009', 'Stub Person');
        $issue = $this->issueEvent(2, $session, $block, 1, 'local:L9', $register['client_event_id']);
        $checkIn = $this->checkInEvent(3, 'local:'.$issue['client_event_id'], $issue['client_event_id']);
        $orphan = $this->checkInEvent(4, 'local:nothing', '01J8ZK4V2Q3W5X6Y7Z8A9B0C9Z');

        $json = $this->sync($device, $actor, [$register, $issue, $checkIn, $orphan])->assertOk()->json();
        $this->assertSame(['conflict', 'pending', 'pending', 'pending'], array_column($json['results'], 'status'));
        $this->assertSame('duplicate_patient', $json['results'][0]['conflict_reason']);
        $this->assertSame('dependency_unresolved', $json['results'][1]['conflict_reason']);
        $this->assertSame($register['client_event_id'], $json['results'][1]['server_result']['depends_on']);
        $this->assertSame(0, Serial::query()->where('session_instance_id', $session->id)->count());

        $existing = Patient::query()->where('mobile', '+8801710000009')->firstOrFail();
        $this->resolve($device, $actor, $register['client_event_id'], 'link_patient', ['patient' => $existing->public_id])->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('server_result.patient.public_id', $existing->public_id);

        // the client re-sends the pending dependants in its next batch
        $again = $this->sync($device, $actor, [$issue, $checkIn])->assertOk()->json();
        $this->assertSame(['accepted', 'accepted'], array_column($again['results'], 'status'));
        $serial = Serial::query()->where('session_instance_id', $session->id)->firstOrFail();
        $this->assertSame($existing->id, $serial->patient_id);
        $this->assertSame('checked_in', $serial->status->value);
        $this->assertSame(1, Patient::query()->where('mobile', '+8801710000009')->count(), 'the stub was discarded, not created');

        $this->assertSame('pending', OfflineEvent::query()->where('client_event_id', $orphan['client_event_id'])->firstOrFail()->status->value);
        $this->asDevice($device, $actor)->getJson(route('api.reception.sync.conflicts', [], false))->assertOk()->assertJsonCount(1, 'events');
    }

    public function test_replay_is_per_device_and_tenant_isolated(): void
    {
        $this->assertTenantIsolated('offline_events', function (): void {
            $device = $this->device();
            $actor = $this->receptionist();
            $session = $this->openSession(30, 0, 5);
            $this->sync($device, $actor, [$this->voidEvent(1, $session, '01J8ZK4V2Q3W5X6Y7Z8A9B0C9Y')])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        });
    }
}

/** Test double: the first check_in handler call in the process dies with a non-domain exception. */
final class CrashOnceCheckIn implements ReplayHandler
{
    public static int $calls = 0;

    public function __construct(private readonly ReplayHandler $inner) {}

    public function handle(OfflineEvent $event, ReplayContext $ctx, ?Resolution $resolution = null): ReplayOutcome
    {
        if (self::$calls++ === 0) {
            throw new RuntimeException('simulated crash');
        }

        return $this->inner->handle($event, $ctx, $resolution);
    }
}
