<?php

declare(strict_types=1);

namespace Tests\Concurrency\Serials;

use App\Domain\Serials\Actions\LeaseBlock;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Shared\Actor;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialEvent;
use App\Models\Tenant\SerialPool;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\Support\ProcessPool;
use Tests\Support\ProcessPoolResult;
use Tests\TestCase;

/**
 * SERIAL_ENGINE §18.1 / CONVENTIONS §6.5: committed state, real OS processes (each with its own container and PDO
 * connection), the same interleaving Octane workers produce.
 */
#[Group('concurrency')]
final class AllocateSerialConcurrencyTest extends TestCase
{
    use SerialFixtures;

    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    protected function tearDown(): void
    {
        $this->truncateTenantTables('a', ['serial_events', 'serials', 'serial_blocks', 'serial_pools', 'session_instances', 'reception_devices', 'audit_logs']);
        parent::tearDown();
    }

    /** @return array<int, string> */
    private function hammer(int $sessionId, string $pool, int $count, string ...$extra): array
    {
        return array_merge(['php', 'artisan', 'serials:hammer', '--tenant=9001', "--session={$sessionId}", "--pool={$pool}", "--count={$count}", '--start-at='.$this->startAt], $extra);
    }

    /** Barrier: every worker of a test sleeps until this instant, so their allocation loops overlap (boot takes ~1 s each). */
    private string $startAt = '0';

    private function barrier(int $seconds = 5): void
    {
        $this->startAt = (string) (microtime(true) + $seconds);
    }

    public function test_600_parallel_allocations_produce_600_unique_in_range_numbers(): void
    {
        $session = $this->openSession(600, 0, 0);
        $this->barrier(6);
        $started = microtime(true);

        $results = ProcessPool::run(workers: 24, command: $this->hammer($session->id, 'counter', 25), timeoutSeconds: 120);
        $elapsed = microtime(true) - $started;

        $this->assertSame(0, $results->failed(), implode("\n", array_slice($results->lines(), 0, 20)));

        $numbers = Serial::query()->where('session_instance_id', $session->id)->pluck('number');
        $pool = SerialPool::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->firstOrFail();

        $this->assertCount(600, $numbers);
        $this->assertSame(600, $numbers->unique()->count(), 'duplicate serial numbers issued');
        $this->assertSame(1, $numbers->min());
        $this->assertSame(600, $numbers->max());
        $this->assertTrue($numbers->every(fn (int $n) => $n >= $pool->range_start && $n <= $pool->range_end));
        $this->assertSame(601, $pool->next_number);
        $this->assertSame(600, $pool->issued_count);
        $this->assertSame(0, SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::NumberSkipped->value)->count());
        $this->assertSame(600, SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::Booked->value)->count());
        $this->assertSame(600, $session->fresh()->booked_count);
        $this->assertLessThan(60, $elapsed, "600 allocations took {$elapsed}s");

        fwrite(STDERR, sprintf("\n[concurrency] 24 processes x 25 allocations = 600 serials, duplicates found = %d, wall %.1fs\n", 600 - $numbers->unique()->count(), $elapsed));
    }

    public function test_parallel_allocations_across_three_pools_and_leased_blocks_stay_in_their_ranges(): void
    {
        // C=300: blocks [1..25]x4 carved from the counter pool first, then 8 desk workers x 25 from the cursor; O=200; B=200.
        $session = $this->openSession(300, 200, 200);

        $blocks = [];
        foreach (ReceptionDevice::factory()->count(4)->create() as $row) {
            $device = $row->id;
            $blocks[$device] = app(LeaseBlock::class)->handle($session, $device, 25, Actor::system(), 25);
        }

        $this->barrier(8);
        $started = microtime(true);
        $groups = [];
        // ProcessPool::run() waits for its workers, so the groups are started from background processes of their own
        // and collected afterwards; the --start-at barrier lines every worker up on the same instant.
        $starts = [];
        $starts[] = $this->startGroup(8, $this->hammer($session->id, 'online', 25));
        $starts[] = $this->startGroup(8, $this->hammer($session->id, 'counter', 25));
        foreach ($blocks as $device => $block) {
            $starts[] = $this->startGroup(1, $this->hammer($session->id, 'counter', 25, "--block={$block->id}", "--device={$device}"));
        }
        foreach ($starts as $processes) {
            foreach ($processes as $p) {
                $p->wait();
            }
            $groups[] = new ProcessPoolResult($processes);
        }
        $elapsed = microtime(true) - $started;

        foreach ($groups as $g) {
            $this->assertSame(0, $g->failed(), implode("\n", array_merge(
                array_slice(array_filter($g->lines(), fn (string $l) => str_contains($l, '"ok":false')), 0, 10),
                array_map(fn ($p) => trim($p->getErrorOutput()), $g->processes),
            )));
        }

        $serials = Serial::query()->where('session_instance_id', $session->id)->get();
        $pools = SerialPool::query()->where('session_instance_id', $session->id)->get()->keyBy(fn (SerialPool $p) => $p->pool->value);

        $this->assertCount(500, $serials);
        $this->assertSame(500, $serials->pluck('number')->unique()->count(), 'duplicate numbers across pools/blocks');

        foreach (['online' => 200, 'counter' => 300, 'buffer' => 0] as $pool => $expected) {
            $inPool = $serials->where('pool', $pool);
            $this->assertCount($expected, $inPool, "{$pool} count");
            $this->assertTrue($inPool->every(fn (Serial $s) => $s->number >= $pools[$pool]->range_start && $s->number <= $pools[$pool]->range_end), "{$pool} out of range");
        }

        foreach ($blocks as $device => $block) {
            $fromBlock = $serials->where('serial_block_id', $block->id);
            $this->assertCount(25, $fromBlock);
            $this->assertTrue($fromBlock->every(fn (Serial $s) => $s->number >= $block->range_start && $s->number <= $block->range_end && $s->reception_device_id === $device));
            $this->assertSame('exhausted', $block->fresh()->status->value);
        }

        $desk = $serials->where('pool', 'counter')->whereNull('serial_block_id');
        $this->assertCount(200, $desk);
        $this->assertTrue($desk->every(fn (Serial $s) => $s->number > 100), 'desk numbers must sit above the leased blocks');
        $this->assertSame(301, $pools['counter']->next_number);
        $this->assertSame(501, $pools['online']->next_number);
        $this->assertSame(0, SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::NumberSkipped->value)->count());

        fwrite(STDERR, sprintf("\n[concurrency] 8 online + 8 counter + 4 block processes = 500 serials, duplicates found = %d, wall %.1fs\n", 500 - $serials->pluck('number')->unique()->count(), $elapsed));
    }

    /**
     * Same environment as ProcessPool::run() (CONVENTIONS §6.5) but returns the started processes without waiting.
     *
     * @param  array<int, string>  $command
     * @return array<int, Process>
     */
    private function startGroup(int $workers, array $command): array
    {
        $env = array_filter([
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'CATALOG_DB_DATABASE' => (string) config('database.connections.catalog.database'),
            'CACHE_STORE' => (string) config('cache.default'), 'QUEUE_CONNECTION' => (string) config('queue.default'),
            'SCOUT_DRIVER' => (string) config('scout.driver'), 'SCOUT_PREFIX' => (string) config('scout.prefix'),
            'REDIS_DB' => (string) config('database.redis.default.database'), 'APP_CENTRAL_DOMAIN' => (string) config('tenancy.central_domain'),
        ], fn ($v) => $v !== '');
        $processes = [];

        for ($i = 0; $i < $workers; $i++) {
            $p = new Process($command, base_path(), $env + ['WORKER_INDEX' => (string) $i], null, 120);
            $p->start();
            $processes[] = $p;
        }

        return $processes;
    }

    public function test_parallel_idempotent_replays_return_the_same_serial(): void
    {
        $session = $this->openSession(10, 50, 0);
        $ulid = '01J8ZK4V2Q3W5X6Y7Z8A9B0C1D';
        $this->barrier(5);

        $results = ProcessPool::run(workers: 10, command: $this->hammer($session->id, 'online', 3, "--client-event-id={$ulid}"), timeoutSeconds: 120);

        $this->assertSame(0, $results->failed(), implode("\n", array_slice($results->lines(), 0, 10)));
        $this->assertSame(1, Serial::query()->where('session_instance_id', $session->id)->count());
        $ids = array_unique(array_map(fn (string $line) => (int) (json_decode($line, true)['serial_id'] ?? 0), $results->lines()));
        $this->assertCount(1, $ids);
        $this->assertSame(12, SerialPool::query()->where('session_instance_id', $session->id)->where('pool', 'online')->value('next_number'));
    }

    /**
     * CONVENTIONS §6.5 / SCHEMA §5.1: with the owner lock disabled the cursor reads race and the same number is
     * handed to several workers — serials_session_number_uniq must still reject every duplicate row; the collisions
     * surface as number_skipped events (and AllocationDriftDetected reports) instead of duplicate serials.
     */
    public function test_unique_index_holds_without_the_owner_lock(): void
    {
        $session = $this->openSession(2000, 0, 0);
        $this->barrier(5);

        $results = ProcessPool::run(workers: 8, command: $this->hammer($session->id, 'counter', 100, '--skip-owner-lock'), timeoutSeconds: 120);

        $numbers = Serial::query()->where('session_instance_id', $session->id)->pluck('number');
        $skipped = SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::NumberSkipped->value)->count();
        $ok = count(array_filter($results->lines(), fn (string $l) => (json_decode($l, true)['ok'] ?? false) === true));

        $this->assertSame($numbers->count(), $numbers->unique()->count(), 'the unique index let a duplicate through');
        $this->assertSame($ok, $numbers->count());
        $this->assertGreaterThan(0, $skipped, 'expected the lock-free run to demonstrate collisions (number_skipped events); none happened');
        $this->assertTrue($numbers->every(fn (int $n) => $n >= 1 && $n <= 2000));

        fwrite(STDERR, sprintf("\n[concurrency] no-lock proof: 8 x 100 attempts, %d rows, %d unique, %d unique-violations caught by the index, worker failures %d\n", $numbers->count(), $numbers->unique()->count(), $skipped, $results->failed()));
    }
}
