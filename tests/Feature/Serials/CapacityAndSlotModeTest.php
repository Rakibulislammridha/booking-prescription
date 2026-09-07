<?php

declare(strict_types=1);

namespace Tests\Feature\Serials;

use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Exceptions\SlotUnavailable;
use App\Domain\Serials\Services\CapacityService;
use App\Domain\Serials\Services\PositionService;
use App\Models\Tenant\SerialEvent;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/** SERIAL_ENGINE §18.7: CapacityServiceTest + SlotModeTest. */
final class CapacityAndSlotModeTest extends TestCase
{
    use SerialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_remaining_and_range_queries_reflect_pools_and_cache_invalidation(): void
    {
        $session = $this->openSession(10, 10, 5);
        $capacity = app(CapacityService::class);

        $this->assertSame(['online' => 10, 'counter' => 10, 'buffer' => 5, 'counter_in_blocks' => 0, 'released' => 0], $capacity->remaining($session->id));
        $this->allocate($session, SerialPool::Online);
        $this->assertSame(9, $capacity->remaining($session->id)['online'], 'allocation forgets the cached value');

        $range = $capacity->remainingForRange($session->doctor_id, $session->branch_id, $this->today()->subDay(), $this->today()->addDay());
        $key = $this->today()->toDateString().':A';
        $this->assertArrayHasKey($key, $range);
        $this->assertSame(9, $range[$key]['online']);
        $this->assertSame($session->public_id, $range[$key]['public_id']);
    }

    public function test_remaining_never_locks_rows(): void
    {
        // The test transaction holds FOR UPDATE on the counter pool from *this* connection; a second PDO connection
        // (the same database) must still read the capacity without blocking — the query uses no lock clause.
        $session = $this->openSession(10, 10, 5);
        DB::table('serial_pools')->where('session_instance_id', $session->id)->where('pool', 'counter')->lockForUpdate()->get();

        $config = config('database.connections.pgsql');
        $pdo = new \PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']), $config['username'], $config['password'], [\PDO::ATTR_TIMEOUT => 3]);
        $pdo->exec('set statement_timeout = 2000');
        $pdo->exec('set search_path to "'.Tenancy::schema().'"');

        // Rows inside the test transaction are invisible to the other connection (uncommitted); the point is that the
        // statement returns instead of waiting on the FOR UPDATE lock. A lock wait would trip the statement timeout.
        $rows = $pdo->query('SELECT p.session_instance_id, p.pool, GREATEST(0, p.range_end - p.next_number + 1) AS remaining FROM serial_pools p WHERE p.session_instance_id = ANY(\'{'.$session->id.'}\')')->fetchAll();
        $this->assertCount(0, $rows, 'uncommitted rows are invisible to the other session, and the statement returned without waiting');

        $locks = $pdo->query("SELECT count(*) FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE c.relname = 'serial_pools' AND l.mode = 'RowExclusiveLock' AND l.pid = pg_backend_pid()")->fetchColumn();
        $this->assertSame(0, (int) $locks, 'the capacity query took a row lock');
        $pdo = null;
    }

    public function test_slot_double_booking_raises_slot_unavailable_not_retry(): void
    {
        $session = SessionInstance::factory()->openToday()->slotMode(15)->quotas(10, 10, 5)->create(['branch_id' => $this->mainBranch()->id]);
        $slot = $session->planned_start_at->addMinutes(30);

        $first = $this->allocate($session, SerialPool::Online, slot: $slot);
        $this->assertSame($slot->getTimestamp(), $first->slot_start_at?->getTimestamp());

        try {
            $this->allocate($session, SerialPool::Counter, slot: $slot);
            $this->fail('expected SlotUnavailable');
        } catch (SlotUnavailable $e) {
            $this->assertSame('serials.slot_taken', $e->code());
            $this->assertSame(409, $e->status());
        }

        $this->assertSame(0, SerialEvent::query()->where('session_instance_id', $session->id)->where('type', 'number_skipped')->count(), 'the number was not retried');
        $this->assertSame(1, DB::table('serials')->where('session_instance_id', $session->id)->count());
        $this->assertSame(1, DB::table('serial_pools')->where('session_instance_id', $session->id)->where('pool', 'counter')->value('next_number'), 'the counter cursor rolled back with the transaction');
    }

    public function test_slot_position_by_slot_index_and_free_slots(): void
    {
        $session = SessionInstance::factory()->openToday()->slotMode(15)->quotas(10, 10, 5)->create(['branch_id' => $this->mainBranch()->id]);
        $capacity = app(CapacityService::class);
        $this->assertCount(16, $capacity->freeSlots($session), '09:00..13:00 in 15-minute steps');

        $third = $this->allocate($session, SerialPool::Online, slot: $session->planned_start_at->addMinutes(30));
        $this->assertSame(3 * PositionService::GAP, $third->position);
        $first = $this->allocate($session, SerialPool::Counter, slot: $session->planned_start_at);
        $this->assertSame(PositionService::GAP, $first->position);
        $walkin = $this->allocate($session, SerialPool::Buffer);
        $this->assertNull($walkin->slot_start_at);
        $this->assertGreaterThan($third->position, $walkin->position, 'walk-ins fill gaps at the tail');

        $free = $capacity->freeSlots($session);
        $this->assertCount(14, $free);
        $this->assertNotContains($session->planned_start_at->utc()->toIso8601String(), $free);
    }
}
