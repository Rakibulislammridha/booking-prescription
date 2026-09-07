<?php

declare(strict_types=1);

namespace Tests\Feature\Serials;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Actions\AllocateFromBlock;
use App\Domain\Serials\Actions\LeaseBlock;
use App\Domain\Serials\Actions\ReleaseBlock;
use App\Domain\Serials\Actions\RevokeBlock;
use App\Domain\Serials\Data\AllocationRequest;
use App\Domain\Serials\Enums\BlockStatus;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Exceptions\BlockLimitReached;
use App\Domain\Serials\Exceptions\BlockNotIssuable;
use App\Domain\Serials\Exceptions\PoolExhausted;
use App\Domain\Serials\Exceptions\SessionNotOpen;
use App\Domain\Serials\Services\CapacityService;
use App\Domain\Serials\Services\PositionService;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SerialEvent;
use App\Models\Tenant\SerialPool as PoolRow;
use App\Models\Tenant\SessionInstance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/** SERIAL_ENGINE §18.3, OFFLINE §4, SCHEMA §5.1.2/§5.1.3: blocks, the free-list and the exclusion constraints. */
final class BlockPoolNonOverlapTest extends TestCase
{
    use SerialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->ensureDevices(7, 8, 9, 10);
    }

    private function issueFromBlock(SerialBlock $block, int $device, int $number, ?string $clientEventId = null): Serial
    {
        return app(AllocateFromBlock::class)->handle($device, $block->fresh(), $number, new AllocationRequest(
            sessionInstanceId: $block->session_instance_id, pool: SerialPool::Counter, source: SerialSource::Offline,
            clientEventId: $clientEventId ?? sprintf('%026s', strtoupper(dechex($block->id * 1000 + $number))),
        ));
    }

    public function test_leased_block_and_pool_allocations_are_disjoint(): void
    {
        $session = $this->openSession(30, 0, 5);
        $block = app(LeaseBlock::class)->handle($session, 7, 10, $this->staffActor(), 10);

        $this->assertSame([1, 10, 1], [$block->range_start, $block->range_end, $block->next_number]);
        $this->assertSame(11, PoolRow::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->value('next_number'));
        $this->assertSame(BlockStatus::Active, $block->status);
        $this->assertSame($session->planned_end_at->addHours(2)->getTimestamp(), $block->expires_at?->getTimestamp());

        $numbers = [];
        for ($i = 1; $i <= 10; $i++) {
            $numbers[] = $this->allocate($session)->number;
            $numbers[] = $this->issueFromBlock($block, 7, $i)->number;
            if ($i % 2 === 0) {
                $numbers[] = $this->allocate($session)->number;
            }
        }

        $this->assertCount(25, $numbers);
        $this->assertCount(25, array_unique($numbers));
        $fromBlock = Serial::query()->where('serial_block_id', $block->id)->orderBy('number')->pluck('number')->all();   // Postgres returns heap order without an ORDER BY
        $this->assertSame(range(1, 10), $fromBlock);
        $fromPool = Serial::query()->where('session_instance_id', $session->id)->whereNull('serial_block_id')->pluck('number');
        $this->assertTrue($fromPool->every(fn (int $n) => $n > 10));
        $this->assertSame(BlockStatus::Exhausted, $block->fresh()->status);
        $this->assertTrue(Serial::query()->where('serial_block_id', $block->id)->get()->every(fn (Serial $s) => $s->source === SerialSource::Offline && $s->reception_device_id === 7));
    }

    public function test_released_block_numbers_are_reissued_lowest_first_and_only_once(): void
    {
        $session = $this->openSession(30, 0, 5);
        $block = app(LeaseBlock::class)->handle($session, 7, 10, $this->staffActor(), 10);
        foreach ([1, 2, 3] as $n) {
            $this->issueFromBlock($block, 7, $n);
        }

        $released = app(ReleaseBlock::class)->handle($block, $this->staffActor(), 'logout');
        $this->assertSame(BlockStatus::Released, $released->status);
        $this->assertSame(7, $released->returned_count);
        $this->assertSame(4, $released->next_number);
        $this->assertThrows(fn () => $this->issueFromBlock($block, 7, 4), BlockNotIssuable::class);

        $numbers = array_map(fn (Serial $s) => $s->number, $this->allocateMany($session, 12));
        $this->assertSame([4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15], $numbers, 'free-list 4..10 first, then the pool cursor');
        $this->assertSame(BlockStatus::Exhausted, $released->fresh()->status);
        $this->assertSame(15, Serial::query()->where('session_instance_id', $session->id)->distinct()->count('number'));

        // a reused low number joins the tail of the calling order
        $late = Serial::query()->where('session_instance_id', $session->id)->where('number', 4)->firstOrFail();
        $this->assertGreaterThan(3 * PositionService::GAP, $late->position);
        $this->assertSame(1, SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::BlockReleased->value)->count());
    }

    public function test_lease_drains_released_ranges_first_and_stays_disjoint(): void
    {
        $session = $this->openSession(30, 0, 5);
        $first = app(LeaseBlock::class)->handle($session, 7, 10, $this->staffActor(), 10);
        app(ReleaseBlock::class)->handle($first, $this->staffActor());

        $second = app(LeaseBlock::class)->handle($session, 8, 4, $this->staffActor(), 4);
        $this->assertSame([1, 4], [$second->range_start, $second->range_end], 'carved from the released row, lowest first');
        $this->assertSame([5, 10, 5], [$first->fresh()->range_start, $first->fresh()->range_end, $first->fresh()->next_number], 'the released row shrank around the carve');
        $this->assertSame(11, PoolRow::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->value('next_number'), 'pool cursor untouched');

        $third = app(LeaseBlock::class)->handle($session, 9, 10, $this->staffActor(), 10);
        $this->assertSame([5, 10], [$third->range_start, $third->range_end]);
        $this->assertSame(BlockStatus::Exhausted, $first->fresh()->status);

        $fourth = app(LeaseBlock::class)->handle($session, 10, 5, $this->staffActor(), 5);
        $this->assertSame([11, 15], [$fourth->range_start, $fourth->range_end], 'then the pool cursor');
    }

    public function test_device_limit_size_clamp_and_closed_session(): void
    {
        $session = $this->openSession(100, 0, 0);
        app(Settings::class)->set('serial.max_active_blocks_per_device', 2);

        $a = app(LeaseBlock::class)->handle($session, 7, 50, $this->staffActor());
        $this->assertSame(5, $a->range_end - $a->range_start + 1, 'clamped to serial.default_block_size when the device has no size');
        $b = app(LeaseBlock::class)->handle($session, 7, 50, $this->staffActor(), 40);
        $this->assertSame(30, $b->range_end - $b->range_start + 1, 'hard maximum 30');

        try {
            app(LeaseBlock::class)->handle($session, 7, 5, $this->staffActor(), 5);
            $this->fail('expected BlockLimitReached');
        } catch (BlockLimitReached $e) {
            $this->assertSame('reception.block_limit', $e->code());
            $this->assertSame(2, $e->active);
        }

        app(ReleaseBlock::class)->handle($a, $this->staffActor());
        $c = app(LeaseBlock::class)->handle($session, 7, 5, $this->staffActor(), 5);
        $this->assertSame([1, 5], [$c->range_start, $c->range_end], 'the released numbers of the same device come back first');

        $small = $this->openSession(3, 0, 0);
        $only = app(LeaseBlock::class)->handle($small, 8, 10, $this->staffActor(), 10);
        $this->assertSame(3, $only->range_end - $only->range_start + 1, 'clamped to remaining');
        $this->assertThrows(fn () => app(LeaseBlock::class)->handle($small, 9, 10, $this->staffActor(), 10), PoolExhausted::class);

        $closed = SessionInstance::factory()->openToday()->closed()->create(['branch_id' => $this->mainBranch()->id]);
        $this->assertThrows(fn () => app(LeaseBlock::class)->handle($closed, 7, 5, $this->staffActor(), 5), SessionNotOpen::class);
    }

    public function test_replay_issues_the_device_number_and_rejects_bad_states(): void
    {
        $session = $this->openSession(30, 0, 5);
        $block = app(LeaseBlock::class)->handle($session, 7, 10, $this->staffActor(), 10);

        $skipped = $this->issueFromBlock($block, 7, 3);   // the device voided slips 1 and 2
        $this->assertSame(3, $skipped->number);
        $this->assertSame(4, $block->fresh()->next_number);
        $this->assertSame($block->id, $skipped->serial_block_id);

        $this->assertThrows(fn () => $this->issueFromBlock($block, 8, 4), BlockNotIssuable::class);       // not the owner
        $this->assertThrows(fn () => $this->issueFromBlock($block, 7, 2), BlockNotIssuable::class);       // below the cursor
        $this->assertThrows(fn () => $this->issueFromBlock($block, 7, 11), BlockNotIssuable::class);      // above the range

        $same = $this->issueFromBlock($block, 7, 3, sprintf('%026s', strtoupper(dechex($block->id * 1000 + 3))));
        $this->assertSame($skipped->id, $same->id, 'replay is idempotent by client_event_id');

        app(RevokeBlock::class)->handle($block, $this->staffActor(), 'tablet lost');
        $fresh = $block->fresh();
        $this->assertTrue($fresh->isRevoked());
        $this->assertSame(BlockStatus::Released, $fresh->status);
        $this->assertSame(7, $fresh->returned_count);
        $this->assertThrows(fn () => $this->issueFromBlock($block, 7, 5), BlockNotIssuable::class);
        $this->assertSame(1, SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::BlockRevoked->value)->count());
        $this->assertSame(1, SerialBlock::query()->where('session_instance_id', $session->id)->count(), 'no second row on revoke');
    }

    public function test_exclusion_constraints_reject_overlapping_pool_and_block_ranges(): void
    {
        $session = $this->openSession(10, 10, 5);
        $counter = PoolRow::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->firstOrFail();

        try {
            DB::transaction(fn () => DB::table('serial_pools')->where('id', $counter->id)->update(['range_end' => 12]));
            $this->fail('serial_pools_range_excl should reject counter [1,12] overlapping online [11,20]');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode());
            $this->assertStringContainsString('serial_pools_range_excl', $e->getMessage());
        }

        $block = app(LeaseBlock::class)->handle($session, 7, 5, $this->staffActor(), 5);

        try {
            DB::transaction(fn () => DB::table('serial_blocks')->insert([
                'public_id' => '01J8ZK4V2Q3W5X6Y7Z8A9B0C1E', 'session_instance_id' => $session->id, 'serial_pool_id' => $counter->id,
                'reception_device_id' => 8, 'range_start' => 3, 'range_end' => 8, 'next_number' => 3, 'status' => 'active',
                'leased_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('serial_blocks_range_excl should reject [3,8] overlapping the leased [1,5]');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode());
            $this->assertStringContainsString('serial_blocks_range_excl', $e->getMessage());
        }

        // an empty (drained) block row never conflicts
        DB::table('serial_blocks')->insert([
            'public_id' => '01J8ZK4V2Q3W5X6Y7Z8A9B0C1F', 'session_instance_id' => $session->id, 'serial_pool_id' => $counter->id,
            'reception_device_id' => null, 'range_start' => 3, 'range_end' => 2, 'next_number' => 3, 'status' => 'exhausted',
            'leased_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(2, SerialBlock::query()->where('session_instance_id', $session->id)->count());
        $this->assertSame($block->id, SerialBlock::query()->active()->where('session_instance_id', $session->id)->value('id'));
    }

    public function test_capacity_reports_blocks_and_released_separately(): void
    {
        $session = $this->openSession(20, 10, 5);
        $active = app(LeaseBlock::class)->handle($session, 7, 5, $this->staffActor(), 5);
        $toRelease = app(LeaseBlock::class)->handle($session, 8, 5, $this->staffActor(), 5);
        $this->issueFromBlock($toRelease, 8, 6);
        app(ReleaseBlock::class)->handle($toRelease, $this->staffActor());
        $this->allocateMany($session, 2, SerialPool::Online);

        $remaining = app(CapacityService::class)->remainingFor([$session->id])[$session->id];

        $this->assertSame(['online' => 8, 'counter' => 10, 'buffer' => 5, 'counter_in_blocks' => 5, 'released' => 4], $remaining);
        $this->assertSame(5, $active->fresh()->remaining());
    }
}
