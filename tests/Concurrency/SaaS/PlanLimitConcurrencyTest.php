<?php

declare(strict_types=1);

namespace Tests\Concurrency\SaaS;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Services\UsageMeter;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ProcessPool;
use Tests\TestCase;

/**
 * A limit that only holds when one person clicks at a time is not a limit — it is a race waiting to be sold as a
 * feature. This is the proof, with REAL parallel processes against COMMITTED state (CONVENTIONS §6.5).
 *
 * The invariant: whatever the interleaving, the number of successful writes is EXACTLY the cap, and the counter
 * ends on the cap. It holds because the reservation is one statement —
 * `INSERT … ON CONFLICT (tenant_id, metric, period) DO UPDATE SET value = value + 1 RETURNING value` — so
 * Postgres serialises the writers on the row and hands each one a distinct post-increment value. A
 * read-then-write gate would let every worker read the same "49" and all of them write "50".
 */
#[Group('concurrency')]
final class PlanLimitConcurrencyTest extends TestCase
{
    /** No transaction: everything this test writes is committed, or the workers could not see it. */
    protected array $connectionsToTransact = [];

    private const LIMIT = 40;

    private const WORKERS = 8;

    private const PER_WORKER = 15;

    protected function tearDown(): void
    {
        // Truncate FIRST, then put the counters back: this test commits everything it writes, and a counter reset
        // that ran before the rows were removed would leave the gauge disagreeing with the schema for every later
        // test in the process.
        $this->truncateTenantTables('a', ['doctor_specialties', 'doctor_pad_settings', 'doctor_profiles', 'doctors', 'audit_logs']);
        $this->restoreUnlimited();
        parent::tearDown();
    }

    public function test_parallel_workers_can_never_take_more_than_the_cap(): void
    {
        $tenant = $this->tenant('a');
        $this->capDoctorsAt(self::LIMIT);
        app(UsageMeter::class)->set($tenant, UsageMetric::Doctors, 0);

        $results = ProcessPool::run(
            workers: self::WORKERS,
            command: ['php', 'artisan', 'saas:limit-hammer', '--tenant=9001', '--metric=doctors', '--mode=reserve', '--count='.self::PER_WORKER, '--start-at='.(microtime(true) + 3.0)],
            timeoutSeconds: 180,
        );

        $this->assertSame(0, $results->failed(), 'a worker process crashed');

        $lines = array_map(fn (string $l) => json_decode($l, true), $results->lines());
        $attempted = self::WORKERS * self::PER_WORKER;
        $granted = count(array_filter($lines, fn ($r) => is_array($r) && ($r['ok'] ?? false) === true));
        $refused = count(array_filter($lines, fn ($r) => is_array($r) && ($r['code'] ?? '') === 'saas.limit.doctors'));

        $this->assertCount($attempted, $lines, 'every attempt must report a result');
        $this->assertSame(self::LIMIT, $granted, "exactly the cap may be granted out of {$attempted} concurrent attempts");
        $this->assertSame($attempted - self::LIMIT, $refused, 'every other attempt must be refused with the limit code, not an error');

        $this->assertSame(self::LIMIT, app(UsageMeter::class)->value($tenant, UsageMetric::Doctors), 'the counter must land exactly on the cap');

        // Each granted attempt got a DISTINCT post-increment value: that is the serialisation, observed.
        $values = array_values(array_map(fn ($r) => (int) $r['value'], array_filter($lines, fn ($r) => is_array($r) && ($r['ok'] ?? false) === true)));
        sort($values);
        $this->assertSame(range(1, self::LIMIT), $values, 'two workers were handed the same counter value');
    }

    public function test_the_cap_holds_through_the_real_model_write_path(): void
    {
        $tenant = $this->tenant('a');
        $this->capDoctorsAt(12);
        $this->truncateTenantTables('a', ['doctor_specialties', 'doctor_pad_settings', 'doctor_profiles', 'doctors']);
        app(UsageMeter::class)->set($tenant, UsageMetric::Doctors, 0);

        $results = ProcessPool::run(
            workers: 6,
            command: ['php', 'artisan', 'saas:limit-hammer', '--tenant=9001', '--metric=doctors', '--mode=doctor', '--count=6', '--start-at='.(microtime(true) + 3.0)],
            timeoutSeconds: 180,
        );

        $this->assertSame(0, $results->failed());

        $doctors = (int) Tenancy::run($tenant, fn (): int => DB::table('doctors')->whereNull('deleted_at')->count());

        $this->assertSame(12, $doctors, '36 concurrent creates against a cap of 12 must leave exactly 12 rows');
        $this->assertSame(12, app(UsageMeter::class)->value($tenant, UsageMetric::Doctors));
    }

    private function capDoctorsAt(int $limit): void
    {
        $this->override(['limit_value' => $limit, 'enabled' => true]);
    }

    private function restoreUnlimited(): void
    {
        $this->override(['limit_value' => null, 'enabled' => true]);
        app(UsageMeter::class)->set($this->tenant('a'), UsageMetric::Doctors, 0);
    }

    /** @param  array<string, mixed>  $value */
    private function override(array $value): void
    {
        $tenant = $this->tenant('a');
        /** @var Subscription $subscription */
        $subscription = Subscription::query()->findOrFail($tenant->current_subscription_id);
        /** @var array<string, mixed> $overrides */
        $overrides = (array) $subscription->feature_overrides;
        $overrides[PlanFeatureKey::Doctors->value] = $value;
        $subscription->forceFill(['feature_overrides' => $overrides])->save();

        Tenant::query()->find($tenant->id);
    }
}
