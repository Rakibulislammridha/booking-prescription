<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial;

use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Tenancy\Adversarial\Support\BatchableRecordJob;
use Tests\Feature\Tenancy\Adversarial\Support\BuildsQueuePayloads;
use Tests\Feature\Tenancy\Adversarial\Support\NestedSyncDispatchJob;
use Tests\Feature\Tenancy\Adversarial\Support\RecordTenantCommand;
use Tests\Feature\Tenancy\Adversarial\Support\ThrowingJob;
use Tests\Support\Jobs\RecordTenantJob;
use Tests\TestCase;

/**
 * Attack surface 6: a long-lived worker processes payloads serialised in other contexts. Payloads are built with the
 * real createPayloadUsing hooks and handed to Illuminate\Queue\Worker, as a Horizon worker would.
 */
final class QueueWorkerTest extends TestCase
{
    use BuildsQueuePayloads;

    protected function setUp(): void
    {
        parent::setUp();
        RecordTenantJob::$runs = [];
        BatchableRecordJob::$runs = [];
        RecordTenantCommand::$seen = [];
    }

    /**
     * EXPECTED TO FAIL until fixed (critical): TenantQueuePayload tracks "did I start this tenancy" in ONE boolean.
     * A job that dispatches anything inline (dispatch_sync, a sync-connection job/listener/notification, a chained
     * job on the sync connection) fires a nested JobProcessing, which overwrites the flag with false; the outer
     * JobProcessed then never ends the tenancy, and the worker keeps tenant A for every following job.
     * Fix: a depth counter / stack (end only when the depth that initialised it unwinds).
     */
    public function test_a_nested_sync_dispatch_inside_a_worker_job_does_not_leak_the_tenant_to_the_worker(): void
    {
        $payload = $this->payloadCreatedIn('a', new NestedSyncDispatchJob);
        $this->assertFalse(Tenancy::check());

        $this->processWithWorker($payload);

        $this->assertSame(9001, RecordTenantJob::$runs[0]['tenant_id']);         // the nested job ran in tenant A (correct)
        $this->assertFalse(Tenancy::check(), 'the worker is still inside tenant '.(string) Tenancy::id().' after the job finished');
        $this->assertSame('public', DB::scalar('show search_path'));
    }

    /**
     * EXPECTED TO FAIL until fixed (critical): when a tenancy is already active in the worker (leaked by the bug above,
     * or by any job that initialises without ending), TenantQueuePayload::onProcessing treats it as "the dispatcher
     * owns it" and runs a payload bound to tenant B inside tenant A — silently. Fix: compare the payload tenant with
     * Tenancy::id() and throw TenantMismatch (as InitializeTenancyForJob does), or end the leaked tenancy first.
     */
    public function test_a_job_bound_to_tenant_b_never_runs_inside_a_leaked_tenant_a(): void
    {
        $payload = $this->payloadCreatedIn('b', new RecordTenantJob);
        Tenancy::initialize($this->tenant('a'));                                   // any leak source

        try {
            $this->processWithWorker($payload);
        } catch (TenantMismatch) {
            // acceptable: refuse loudly
        }

        $ran = RecordTenantJob::$runs[0] ?? null;
        $this->assertNotSame(9001, $ran['tenant_id'] ?? null, 'a payload for tenant 9002 executed inside tenant 9001 (search_path '.($ran['search_path'] ?? '-').')');
        $this->assertNotSame(self::TENANT_A, $ran['search_path'] ?? null);
    }

    /** EXPECTED TO FAIL until fixed (critical): the same for a payload dispatched centrally (tenant_id null). */
    public function test_a_central_job_never_runs_inside_a_leaked_tenant(): void
    {
        $payload = $this->payloadCreatedIn(null, new RecordTenantJob);
        Tenancy::initialize($this->tenant('a'));

        try {
            $this->processWithWorker($payload);
        } catch (TenantMismatch) {
        }

        $ran = RecordTenantJob::$runs[0] ?? null;
        $this->assertNull($ran['payload_tenant_id'] ?? null);
        $this->assertNotSame(9001, $ran['tenant_id'] ?? null, 'a centrally dispatched job executed inside tenant 9001');
    }

    /** Guarantee: a failing tenant job leaves the worker with no tenancy and on public. */
    public function test_a_failing_job_leaves_no_tenancy_for_the_next_job_in_the_worker(): void
    {
        $payload = $this->payloadCreatedIn('a', new ThrowingJob);

        try {
            $this->processWithWorker($payload);
            $this->fail('the job should have thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fails on purpose', $e->getMessage());
        }

        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));

        // and the next payload, for tenant B, runs in tenant B
        $this->processWithWorker($this->payloadCreatedIn('b', new RecordTenantJob));
        $this->assertSame(9002, RecordTenantJob::$runs[0]['tenant_id']);
        $this->assertSame(self::TENANT_B, RecordTenantJob::$runs[0]['search_path']);
        $this->assertFalse(Tenancy::check());
    }

    /** Guarantee: every link of a chain started in tenant A carries tenant A in its payload and runs there. */
    public function test_a_chain_started_in_tenant_a_runs_every_link_in_tenant_a(): void
    {
        $payload = $this->payloadCreatedIn('a', (new RecordTenantJob)->chain([new RecordTenantJob, new RecordTenantJob]));

        $this->processWithWorker($payload);

        $this->assertCount(3, RecordTenantJob::$runs);
        $this->assertSame([9001, 9001, 9001], array_column(RecordTenantJob::$runs, 'payload_tenant_id'));
        $this->assertSame([9001, 9001, 9001], array_column(RecordTenantJob::$runs, 'tenant_id'));
        $this->assertSame([self::TENANT_A, self::TENANT_A, self::TENANT_A], array_column(RecordTenantJob::$runs, 'search_path'));
    }

    /** Guarantee: batches record in public.job_batches (qualified) and their jobs run in the dispatching tenant. */
    public function test_a_batch_dispatched_in_tenant_a_runs_in_tenant_a_and_records_in_public_job_batches(): void
    {
        $this->asTenant('a');

        $batch = Bus::batch([new BatchableRecordJob, new BatchableRecordJob])->name('adversarial')->dispatch();

        $this->assertSame([9001, 9001], array_column(BatchableRecordJob::$runs, 'tenant_id'));
        $this->assertSame([self::TENANT_A, self::TENANT_A], array_column(BatchableRecordJob::$runs, 'search_path'));
        $this->assertTrue(DB::table('public.job_batches')->where('id', $batch->id)->exists());
        $this->assertSame(9001, Tenancy::id());
    }

    /** Guarantee: tenants:run enters each tenant in turn and leaves nothing behind. */
    public function test_tenants_run_fans_out_with_a_clean_context_per_tenant(): void
    {
        $this->app->make(Kernel::class)->registerCommand(new RecordTenantCommand);

        $this->artisan('tenants:run', ['artisan' => 'adversarial:record-tenant'])->assertSuccessful();

        $this->assertSame([
            ['tenant_id' => 9001, 'search_path' => self::TENANT_A],
            ['tenant_id' => 9002, 'search_path' => self::TENANT_B],
        ], RecordTenantCommand::$seen);
        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
    }
}
