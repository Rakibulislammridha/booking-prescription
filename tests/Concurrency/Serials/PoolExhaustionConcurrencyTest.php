<?php

declare(strict_types=1);

namespace Tests\Concurrency\Serials;

use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialPool;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\Support\ProcessPool;
use Tests\TestCase;

/** SERIAL_ENGINE §18.2: exhaustion is exact under concurrency — the 26th request fails, never the 24th or 27th. */
#[Group('concurrency')]
final class PoolExhaustionConcurrencyTest extends TestCase
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
        $this->truncateTenantTables('a', ['serial_events', 'serials', 'serial_blocks', 'serial_pools', 'session_instances', 'audit_logs']);
        parent::tearDown();
    }

    public function test_exhaustion_under_concurrency(): void
    {
        $session = $this->openSession(25, 0, 0);

        $results = ProcessPool::run(workers: 30, command: ['php', 'artisan', 'serials:hammer', '--tenant=9001', "--session={$session->id}", '--pool=counter', '--count=1'], timeoutSeconds: 120);

        $lines = array_map(fn (string $l) => json_decode($l, true), $results->lines());
        $ok = array_filter($lines, fn ($l) => ($l['ok'] ?? false) === true);
        $exhausted = array_filter($lines, fn ($l) => ($l['code'] ?? null) === 'serials.pool_exhausted');

        $this->assertCount(25, $ok);
        $this->assertCount(5, $exhausted);
        $this->assertSame(5, $results->failed());
        $this->assertSame(25, Serial::query()->where('session_instance_id', $session->id)->count());
        $this->assertSame(25, Serial::query()->where('session_instance_id', $session->id)->distinct()->count('number'));
        $this->assertSame(26, SerialPool::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->value('next_number'));
    }
}
