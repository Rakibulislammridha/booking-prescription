<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scheduling;

use App\Models\Tenant\SerialPool;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\Support\ProcessPool;
use Tests\TestCase;

/** SERIAL_ENGINE §18.5: 12 processes materialising the same date create exactly one instance (+ three pools) per session. */
#[Group('concurrency')]
final class MaterialiserConcurrencyTest extends TestCase
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
        $this->truncateTenantTables('a', ['serial_events', 'serials', 'serial_blocks', 'serial_pools', 'session_instances', 'schedule_overrides', 'doctor_schedules', 'audit_logs']);
        parent::tearDown();
    }

    public function test_concurrent_ensure_creates_exactly_one_instance(): void
    {
        $doctor = $this->doctorWithTemplate();
        $date = Clock::today()->addDays(3)->toDateString();

        $results = ProcessPool::run(workers: 12, command: ['php', 'artisan', 'sessions:materialise', '--tenant=9001', "--date={$date}", '--sync'], timeoutSeconds: 120);

        $this->assertSame(0, $results->failed(), implode("\n", $results->outputs()));

        $instances = SessionInstance::query()->where('doctor_id', $doctor->id)->whereDate('session_date', $date)->get();
        $this->assertCount(1, $instances);
        $this->assertSame(3, SerialPool::query()->where('session_instance_id', $instances->first()->id)->count());

        $created = array_sum(array_map(fn (string $o) => (int) preg_match('/ 1 instance\(s\) created/', $o), $results->outputs()));
        $this->assertSame(1, $created, 'exactly one process must report having created the instance');
    }
}
