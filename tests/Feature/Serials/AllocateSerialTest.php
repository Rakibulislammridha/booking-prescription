<?php

declare(strict_types=1);

namespace Tests\Feature\Serials;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialAllocated;
use App\Domain\Serials\Exceptions\PoolExhausted;
use App\Domain\Serials\Exceptions\SessionNotAcceptingSerials;
use App\Domain\Serials\Services\PositionService;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialEvent;
use App\Models\Tenant\SerialPool as PoolRow;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

final class AllocateSerialTest extends TestCase
{
    use SerialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_pools_are_laid_out_counter_online_buffer(): void
    {
        $session = $this->openSession(10, 10, 5);
        $pools = $session->pools()->get()->keyBy(fn (PoolRow $p) => $p->pool->value);

        $this->assertSame([1, 10, 1], [$pools['counter']->range_start, $pools['counter']->range_end, $pools['counter']->next_number]);
        $this->assertSame([11, 20, 11], [$pools['online']->range_start, $pools['online']->range_end, $pools['online']->next_number]);
        $this->assertSame([21, 25, 21], [$pools['buffer']->range_start, $pools['buffer']->range_end, $pools['buffer']->next_number]);
    }

    public function test_zero_quota_pool_is_an_empty_range_that_never_conflicts(): void
    {
        $session = $this->openSession(10, 0, 5);
        $online = $session->pools()->where('pool', 'online')->firstOrFail();

        $this->assertSame(11, $online->range_start);
        $this->assertSame(10, $online->range_end);
        $this->assertSame(11, $online->next_number);
        $this->assertThrows(fn () => $this->allocate($session, SerialPool::Online), PoolExhausted::class);
    }

    public function test_allocation_takes_the_next_number_writes_event_audit_counts_and_version(): void
    {
        Event::fake([SerialAllocated::class]);
        $session = $this->openSession();

        $serial = $this->allocate($session, SerialPool::Counter);

        $this->assertSame(1, $serial->number);
        $this->assertSame('A-001', $serial->display_code);
        $this->assertSame(PositionService::GAP, $serial->position);
        $this->assertSame(SerialStatus::Booked, $serial->status);
        $this->assertSame(SerialPool::Counter, $serial->pool);
        $this->assertSame(26, strlen($serial->public_id));

        $second = $this->allocate($session, SerialPool::Counter);
        $this->assertSame(2, $second->number);
        $this->assertSame(2 * PositionService::GAP, $second->position);

        $online = $this->allocate($session, SerialPool::Online);
        $this->assertSame(11, $online->number);
        $this->assertSame('A-011', $online->display_code);

        $walkin = $this->allocate($session, SerialPool::Buffer);
        $this->assertSame(21, $walkin->number);
        $this->assertSame(SerialSource::Walkin, $walkin->source);

        $fresh = $session->fresh();
        $this->assertSame(4, $fresh->booked_count);
        $this->assertSame(5, $fresh->version);
        $this->assertSame(3, PoolRow::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->value('next_number'));
        $this->assertSame(4, SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::Booked->value)->count());
        $this->assertAudited(AuditAction::Create, $serial);
        Event::assertDispatched(SerialAllocated::class, 4);
    }

    public function test_exact_boundary_the_26th_fails_and_cursor_stops_at_range_end_plus_one(): void
    {
        $session = $this->openSession(25, 0, 0);
        $numbers = array_map(fn (Serial $s) => $s->number, $this->allocateMany($session, 25));

        $this->assertSame(range(1, 25), $numbers);

        try {
            $this->allocate($session);
            $this->fail('expected PoolExhausted');
        } catch (PoolExhausted $e) {
            $this->assertSame('serials.pool_exhausted', $e->code());
            $this->assertSame(409, $e->status());
            $this->assertSame(0, $e->remaining['counter']);
        }

        $this->assertSame(26, PoolRow::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->value('next_number'));
        $this->assertSame(25, Serial::query()->where('session_instance_id', $session->id)->count());
    }

    public function test_online_never_spills_into_counter(): void
    {
        $session = $this->openSession(10, 2, 5);
        $this->allocateMany($session, 2, SerialPool::Online);

        $this->assertThrows(fn () => $this->allocate($session, SerialPool::Online), PoolExhausted::class);
        $this->assertSame(1, PoolRow::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->value('next_number'));
        $this->assertSame(0, Serial::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->count());
    }

    public function test_kiosk_draws_from_online_and_followup_pool_depends_on_actor(): void
    {
        $this->assertSame(SerialPool::Online, SerialSource::Kiosk->defaultPool());
        $this->assertSame(SerialPool::Counter, SerialSource::Followup->defaultPool(staffActor: true));
        $this->assertSame(SerialPool::Online, SerialSource::Followup->defaultPool(staffActor: false));
        $this->assertSame(SerialPool::Buffer, SerialSource::Walkin->defaultPool());
    }

    public function test_same_client_event_id_returns_the_same_serial(): void
    {
        $session = $this->openSession();
        $ulid = '01J8ZK4V2Q3W5X6Y7Z8A9B0C1D';

        $a = $this->allocate($session, SerialPool::Online, clientEventId: $ulid);
        $b = $this->allocate($session, SerialPool::Online, clientEventId: $ulid);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Serial::query()->where('session_instance_id', $session->id)->count());
    }

    public function test_closed_or_cancelled_session_refuses_allocation(): void
    {
        $session = SessionInstance::factory()->openToday()->closed()->create(['branch_id' => $this->mainBranch()->id]);

        $this->assertThrows(fn () => $this->allocate($session), SessionNotAcceptingSerials::class);
    }

    public function test_priority_allocation_runs_priority_insert_in_the_same_transaction(): void
    {
        $session = $this->openSession();
        $this->allocateMany($session, 3);
        $emergency = $this->allocate($session, SerialPool::Buffer, priority: SerialPriority::Emergency);

        $this->assertSame(SerialPriority::Emergency, $emergency->priority);
        $this->assertLessThan(PositionService::GAP, $emergency->position);
        $this->assertSame(21, $emergency->number);
        $this->assertSame(1, SerialEvent::query()->where('serial_id', $emergency->id)->where('type', SerialEventType::PriorityChanged->value)->count());
    }

    public function test_serial_tables_are_tenant_isolated(): void
    {
        $this->assertTenantIsolated('serials', function (): void {
            $this->allocate($this->openSession());
        });
    }
}
