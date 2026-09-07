<?php

declare(strict_types=1);

namespace Tests\Concurrency\Queue;

use App\Domain\Queue\Services\QueueStateRepository;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Queue\Concerns\QueueFixtures;
use Tests\Support\ProcessPool;
use Tests\TestCase;

/**
 * REALTIME.md §13.1 — the `qs-build:{session}` lock: a snapshot for version N can never be overwritten by a slower
 * writer that read N-1, and a cold cache rebuilds without a thundering herd.
 */
#[Group('concurrency')]
#[Group('realtime')]
final class QueueStateConcurrencyTest extends TestCase
{
    use QueueFixtures;

    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    protected function tearDown(): void
    {
        $this->truncateTenantTables('a', ['serial_events', 'serials', 'serial_pools', 'session_instances', 'audit_logs']);
        parent::tearDown();
    }

    public function test_version_is_monotonic_under_parallel_rebuilds(): void
    {
        $session = $this->queueSession($this->queueDoctor('dr-hammer-'.uniqid()));
        $this->issueMany($session, 5);
        $repository = app(QueueStateRepository::class);
        $repository->forget($session);
        $before = (int) $session->refresh()->version;

        $result = ProcessPool::run(
            workers: 8,
            command: ['php', 'artisan', 'queue:hammer', '--tenant=9001', "--session={$session->id}", '--count=6', '--bump', '--start-at='.(microtime(true) + 1.5)],
            timeoutSeconds: 180,
        );

        $this->assertSame(0, $result->failed(), implode("\n", $result->lines()));

        $session->refresh();
        $mirror = $repository->version($session);
        $document = json_decode((string) $repository->snapshot($session), true);

        $this->assertIsArray($document);
        $this->assertSame($session->version, $mirror, 'the Redis mirror must equal the committed version');
        $this->assertSame($mirror, $document['version'], 'the document and the mirror must never disagree');
        $this->assertSame(48, $session->version - $before, '8 workers × 6 bumps, one version each, no lost update');
        $this->assertCount(5, $document['serials']);
    }

    public function test_a_cold_cache_is_rebuilt_once_per_version_without_a_thundering_herd(): void
    {
        $session = $this->queueSession($this->queueDoctor('dr-cold-'.uniqid()));
        $this->issueMany($session, 3);
        $repository = app(QueueStateRepository::class);
        $repository->forget($session);

        $this->assertNull($repository->version($session));

        $result = ProcessPool::run(
            workers: 10,
            command: ['php', 'artisan', 'queue:hammer', '--tenant=9001', "--session={$session->id}", '--count=3', '--start-at='.(microtime(true) + 1.5)],
            timeoutSeconds: 180,
        );

        $this->assertSame(0, $result->failed(), implode("\n", $result->lines()));

        $session->refresh();
        $document = json_decode((string) $repository->snapshot($session), true);

        $this->assertIsArray($document);
        $this->assertSame($session->version, (int) $repository->version($session));
        $this->assertSame($session->version, $document['version'], 'no rebuild bumps the version: 30 rebuilds of one committed row');

        // every worker's line reports the same version — the row never moved, so neither did the document
        $versions = [];
        foreach ($result->lines() as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['version'])) {
                $versions[] = (int) $decoded['version'];
            }
        }

        $this->assertCount(30, $versions);
        $this->assertSame([$session->version], array_values(array_unique($versions)));
        $this->assertNotNull(SessionInstance::query()->find($session->id));
        $this->assertSame(9001, (int) Tenancy::id());
    }
}
