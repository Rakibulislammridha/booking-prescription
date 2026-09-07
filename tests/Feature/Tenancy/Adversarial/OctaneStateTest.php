<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinic\Services\ActiveBranch;
use App\Tenancy\Facades\Tenancy as TenancyFacade;
use App\Tenancy\Octane\AssertNoTenancy;
use App\Tenancy\Queue\TenantQueuePayload;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Octane\CurrentApplication;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/** Attack surface 8: state that survives from one Octane operation to the next inside one worker. */
final class OctaneStateTest extends TestCase
{
    /**
     * EXPECTED TO FAIL until fixed (medium): TenancyServiceProvider registers
     * `Feature::resolveScopeUsing(fn () => $this->app->make(Tenancy::class)->current())`. Under Octane `$this->app`
     * is the BASE container, while the request's ResolveTenant initialises the Tenancy singleton of the request
     * SANDBOX (facade → CurrentApplication). In the first request of every worker (before anything resolved Tenancy
     * in the base container) Pennant therefore evaluates every feature/plan flag with a null scope.
     * Fix: resolve through the current container (`fn () => TenancyFacade::current()` / `app(Tenancy::class)`).
     */
    public function test_pennant_resolves_the_tenant_scope_in_the_first_request_of_an_octane_worker(): void
    {
        Feature::define('adversarial.scope-id', fn (mixed $scope): mixed => $scope);          // FeatureScopeable → 'tenant:9001'

        $base = $this->app;
        $base->forgetInstance(Tenancy::class);
        $base->forgetInstance(TenantContext::class);
        $sandbox = clone $base;
        CurrentApplication::set($sandbox);

        try {
            TenancyFacade::initialize($this->tenant('a'));                        // as ResolveTenant does, in the sandbox
            $this->assertSame(9001, TenancyFacade::id());
            $this->assertSame('tenant:9001', Feature::value('adversarial.scope-id'), 'Pennant resolved the default scope through the base container: features are evaluated without a tenant in the first request of each worker');
        } finally {
            TenancyFacade::check() && TenancyFacade::end();
            CurrentApplication::set($base);
        }
    }

    /** Guarantee: AssertNoTenancy repairs a leaked tenant (in memory or only on the connection) before the next operation. */
    public function test_assert_no_tenancy_repairs_a_leaked_tenant_before_the_next_operation(): void
    {
        TenancyFacade::initialize($this->tenant('a'));
        $this->fireRequestReceived();
        $this->assertFalse(TenancyFacade::check());
        $this->assertSame('public', DB::scalar('show search_path'));

        // in-memory context flushed by Octane (config/octane.php 'flush') but the connection still on the tenant
        TenancyFacade::initialize($this->tenant('b'));
        $this->app->forgetInstance(TenantContext::class);
        $this->assertNull($this->app->make(TenantContext::class)->tenant);
        $this->fireRequestReceived();
        $this->assertSame('public', DB::scalar('show search_path'));
        $this->assertSame('public', DB::connection('pgsql')->getConfig('search_path'));
    }

    /**
     * Guarantee (was a documented gap): Tenancy is flushed together with the TenantContext it holds, so after Octane's
     * per-operation flush the facade answers from the new, empty context — one context per request, never two. The
     * connection may still carry the old search path at that point; AssertNoTenancy repairs it (test above).
     */
    public function test_tenancy_is_flushed_with_its_context_so_there_is_one_context_per_operation(): void
    {
        $flush = (array) config('octane.flush');
        foreach ([Tenancy::class, TenantContext::class, TenantResolver::class, TenantQueuePayload::class, ActiveBranch::class, AuditRecorder::class] as $binding) {
            $this->assertContains($binding, $flush);
        }

        TenancyFacade::initialize($this->tenant('a'));
        foreach ($flush as $binding) {
            $this->app->forgetInstance($binding);
        }

        $this->assertNull(TenancyFacade::id());                                           // the new context, via the singleton
        $this->assertNull($this->app->make(TenantContext::class)->tenant);              // and via the container
        $this->assertSame($this->app->make(TenantContext::class), (fn () => $this->context)->call($this->app->make(Tenancy::class)));

        $this->fireRequestReceived();                                                    // repairs the connection left on tenant A
        $this->assertSame('public', DB::scalar('show search_path'));
    }

    private function fireRequestReceived(): void
    {
        (new AssertNoTenancy)->handle(new RequestReceived($this->app, $this->app, Request::create('/')));
    }
}
