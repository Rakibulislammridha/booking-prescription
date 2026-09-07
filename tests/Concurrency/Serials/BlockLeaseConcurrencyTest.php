<?php

declare(strict_types=1);

namespace Tests\Concurrency\Serials;

use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SerialPool;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\Support\ProcessPool;
use Tests\TestCase;

/** SERIAL_ENGINE §18.3 / OFFLINE §12.2: six devices leasing 10 in parallel get pairwise disjoint, contiguous ranges. */
#[Group('concurrency')]
final class BlockLeaseConcurrencyTest extends TestCase
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

    public function test_two_devices_get_disjoint_blocks_under_concurrency(): void
    {
        $session = $this->openSession(100, 0, 0);

        $processes = [];
        foreach (ReceptionDevice::factory()->count(6)->create() as $row) {
            $device = $row->id;
            $processes[] = ProcessPool::run(workers: 1, command: ['php', 'artisan', 'serials:hammer', '--tenant=9001', "--session={$session->id}", "--device={$device}", '--lease=10'], timeoutSeconds: 120);
        }

        foreach ($processes as $p) {
            $this->assertSame(0, $p->failed(), implode("\n", $p->lines()));
        }

        $blocks = SerialBlock::query()->where('session_instance_id', $session->id)->orderBy('range_start')->get();
        $this->assertCount(6, $blocks);

        $covered = [];
        foreach ($blocks as $b) {
            $this->assertSame(10, $b->range_end - $b->range_start + 1);
            $this->assertSame($b->range_start, $b->next_number);
            $covered = array_merge($covered, range($b->range_start, $b->range_end));
        }

        $this->assertSame(range(1, 60), $covered, 'blocks must be pairwise disjoint and contiguous from 1');
        $this->assertSame(61, SerialPool::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->value('next_number'));
        $this->assertSame(6, $blocks->pluck('reception_device_id')->unique()->count());
    }
}
