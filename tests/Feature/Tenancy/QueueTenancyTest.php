<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Tests\Support\Jobs\RecordTenantJob;
use Tests\Support\Jobs\TenantAwareRecordJob;
use Tests\TestCase;

final class QueueTenancyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordTenantJob::$runs = [];
        TenantAwareRecordJob::$runs = [];
    }

    public function test_payload_carries_the_tenant_id_and_the_job_runs_inside_the_dispatching_tenant(): void
    {
        $this->asTenant('a');

        RecordTenantJob::dispatch();

        $this->assertCount(1, RecordTenantJob::$runs);
        $this->assertSame(9001, RecordTenantJob::$runs[0]['payload_tenant_id']);
        $this->assertSame(9001, RecordTenantJob::$runs[0]['tenant_id']);
        $this->assertSame(self::TENANT_A, RecordTenantJob::$runs[0]['search_path']);

        // sync driver: the dispatching context keeps its tenancy after the job finished
        $this->assertSame(9001, Tenancy::id());
        $this->assertSame(self::TENANT_A, DB::scalar('show search_path'));
    }

    public function test_central_dispatch_carries_null_and_runs_centrally(): void
    {
        RecordTenantJob::dispatch();

        $this->assertNull(RecordTenantJob::$runs[0]['payload_tenant_id']);
        $this->assertNull(RecordTenantJob::$runs[0]['tenant_id']);
        $this->assertSame('public', RecordTenantJob::$runs[0]['search_path']);
    }

    public function test_tenant_aware_job_dispatched_from_central_initialises_and_ends_its_tenant(): void
    {
        dispatch((new TenantAwareRecordJob)->forTenant($this->tenant('b')));

        $this->assertSame(9002, TenantAwareRecordJob::$runs[0]['tenant_id']);
        $this->assertSame(self::TENANT_B, TenantAwareRecordJob::$runs[0]['search_path']);
        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
    }

    public function test_tenant_aware_job_never_silently_switches_tenants(): void
    {
        $this->asTenant('a');

        $this->expectException(TenantMismatch::class);

        dispatch((new TenantAwareRecordJob)->forTenant(9002));
    }

    public function test_tenant_aware_tags_name_the_tenant_for_horizon(): void
    {
        $this->assertSame(['tenant:9002'], (new TenantAwareRecordJob)->forTenant(9002)->tags());
        $this->assertSame(['tenant:central'], (new TenantAwareRecordJob)->tags());
    }
}
