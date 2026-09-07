<?php

declare(strict_types=1);

namespace Tests\Concurrency\Reception;

use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\OfflineEvent;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\Serial;
use App\Models\Tenant\User;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\Reception\Concerns\ReceptionFixtures;
use Tests\Support\ProcessPoolResult;
use Tests\TestCase;

/**
 * OFFLINE §12.2 / CONVENTIONS §6.5: six devices replay their offline logs simultaneously against one session (each
 * from its own block) — every number issued exactly once, every event accepted; the same device replaying the same
 * batch twice in parallel processes lands every event exactly once too.
 */
#[Group('concurrency')]
final class SyncReplayConcurrencyTest extends TestCase
{
    use ReceptionFixtures;

    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    protected function tearDown(): void
    {
        $this->truncateTenantTables('a', ['offline_events', 'appointments', 'serial_events', 'serials', 'serial_blocks', 'serial_pools', 'session_instances', 'reception_devices', 'patient_relations', 'patients', 'audit_logs']);
        parent::tearDown();
    }

    public function test_six_devices_replaying_interleaved_logs_never_share_a_number(): void
    {
        $session = $this->openSession(200, 0, 0);
        $actor = User::factory()->create(['default_branch_id' => $this->mainBranch()->id]);
        $actor->assignRole(Role::Receptionist->value);
        $devices = ReceptionDevice::factory()->count(6)->create(['branch_id' => $this->mainBranch()->id, 'block_size' => 20]);
        $blocks = [];

        foreach ($devices as $device) {
            $blocks[$device->id] = $this->leaseFor($device, $session, 20);
        }

        $startAt = (string) (microtime(true) + 7);
        $processes = [];

        foreach ($devices as $device) {
            $processes[] = $this->start(['php', 'artisan', 'reception:sync-hammer', '--tenant=9001', "--device={$device->id}", "--actor={$actor->id}", "--block={$blocks[$device->id]->id}", '--batches=3', "--start-at={$startAt}"]);
        }

        foreach ($processes as $p) {
            $p->wait();
        }

        $result = new ProcessPoolResult($processes);
        $this->assertSame(0, $result->failed(), implode("\n", array_merge(array_slice(array_filter($result->lines(), fn (string $l) => str_contains($l, '"ok":false')), 0, 10), array_map(fn ($p) => trim($p->getErrorOutput()), $processes))));

        $serials = Serial::query()->where('session_instance_id', $session->id)->get();
        $this->assertCount(120, $serials);
        $this->assertSame(120, $serials->pluck('number')->unique()->count(), 'duplicate numbers across devices');
        $this->assertTrue($serials->every(fn (Serial $s) => $s->status->value === 'checked_in' && $s->source->value === 'offline'));

        foreach ($blocks as $deviceId => $block) {
            $mine = $serials->where('reception_device_id', $deviceId);
            $this->assertCount(20, $mine);
            $this->assertTrue($mine->every(fn (Serial $s) => $s->number >= $block->range_start && $s->number <= $block->range_end));
            $this->assertSame('exhausted', $block->fresh()->status->value);
        }

        $this->assertSame(360, OfflineEvent::query()->where('status', 'accepted')->count());
        $this->assertSame(120, Appointment::query()->where('session_instance_id', $session->id)->count());

        fwrite(STDERR, sprintf("\n[concurrency] 6 devices x 20 offline serials replayed in 3 batches each = %d serials, duplicates found = %d\n", $serials->count(), 120 - $serials->pluck('number')->unique()->count()));
    }

    public function test_concurrent_batches_from_the_same_device_land_every_event_exactly_once(): void
    {
        $session = $this->openSession(100, 0, 0);
        $actor = User::factory()->create(['default_branch_id' => $this->mainBranch()->id]);
        $actor->assignRole(Role::Receptionist->value);
        $device = ReceptionDevice::factory()->create(['branch_id' => $this->mainBranch()->id, 'block_size' => 30]);
        $block = $this->leaseFor($device, $session, 30);

        // the log is generated deterministically per (device, number) in the hammer; two workers replay it at once
        $startAt = (string) (microtime(true) + 6);
        $processes = [
            $this->start(['php', 'artisan', 'reception:sync-hammer', '--tenant=9001', "--device={$device->id}", "--actor={$actor->id}", "--block={$block->id}", '--twice', "--start-at={$startAt}"]),
            $this->start(['php', 'artisan', 'reception:sync-hammer', '--tenant=9001', "--device={$device->id}", "--actor={$actor->id}", "--block={$block->id}", "--start-at={$startAt}"]),
        ];

        foreach ($processes as $p) {
            $p->wait();
        }

        $serials = Serial::query()->where('session_instance_id', $session->id)->get();
        $this->assertSame($serials->count(), $serials->pluck('number')->unique()->count(), 'a number was issued twice');
        $this->assertTrue($serials->every(fn (Serial $s) => $s->number >= $block->range_start && $s->number <= $block->range_end));
        $this->assertLessThanOrEqual(60, $serials->count());
        $this->assertSame(0, Serial::query()->where('session_instance_id', $session->id)->count() - $serials->pluck('client_event_id')->unique()->count(), 'one serial per client_event_id');
    }

    /** @param  array<int, string>  $command */
    private function start(array $command): Process
    {
        $env = array_filter([
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'CATALOG_DB_DATABASE' => (string) config('database.connections.catalog.database'),
            'CACHE_STORE' => (string) config('cache.default'), 'QUEUE_CONNECTION' => (string) config('queue.default'),
            'SCOUT_DRIVER' => (string) config('scout.driver'), 'SCOUT_PREFIX' => (string) config('scout.prefix'),
            'REDIS_DB' => (string) config('database.redis.default.database'), 'APP_CENTRAL_DOMAIN' => (string) config('tenancy.central_domain'),
        ], fn ($v) => $v !== '');
        $process = new Process($command, base_path(), $env, null, 180);
        $process->start();

        return $process;
    }
}
