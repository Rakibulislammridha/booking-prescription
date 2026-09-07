<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\SaaS\Enums\TenantStatus;
use App\Http\Middleware\SetActiveBranch;
use App\Models\Tenant\Branch;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Http\Middleware\EnsureSessionBelongsToTenant;
use App\Tenancy\Http\Middleware\EnsureTenantIsActive;
use App\Tenancy\Http\Middleware\RequireTenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Middleware priority (bootstrap/app.php): the tenant group runs after StartSession + EnsureSessionBelongsToTenant
 * and before auth / throttling / SubstituteBindings, on every surface that composes ['web'|'api', 'tenant'].
 */
final class MiddlewareOrderTest extends TestCase
{
    public function test_a_tenant_bound_route_on_the_central_or_an_unknown_host_is_a_404_not_a_500(): void
    {
        Route::middleware(['web', 'tenant', 'auth:web', SetActiveBranch::class])->prefix('panel')->name('panel.')
            ->get('adversarial/branches/{branch:public_id}', fn (Branch $branch) => $branch->name)->name('adversarial.branch');
        Route::middleware(['api', 'tenant', 'auth:web'])->prefix('api')->name('api.')
            ->get('adversarial/branches/{branch:public_id}', fn (Branch $branch) => $branch->name)->name('adversarial.branch');

        $this->asTenant('a');
        $branch = Branch::query()->where('is_main', true)->firstOrFail();
        $this->actingAsStaff();
        $this->get('/panel/adversarial/branches/'.$branch->public_id)->assertOk()->assertSee($branch->name);
        $this->getJson('/api/adversarial/branches/'.$branch->public_id)->assertOk();

        foreach (['super.bp.test', 'bp.test', 'nobody.bp.test'] as $host) {
            $this->app['auth']->forgetGuards();
            Tenancy::check() && Tenancy::end();

            $this->withServerVariables(['HTTP_HOST' => $host])->get('/panel/adversarial/branches/'.$branch->public_id)->assertNotFound();
            $this->withServerVariables(['HTTP_HOST' => $host])->getJson('/api/adversarial/branches/'.$branch->public_id)->assertNotFound();
        }

        $this->assertFalse(Tenancy::check());
    }

    public function test_a_suspended_tenant_answers_402_before_auth_and_bindings(): void
    {
        Route::middleware(['web', 'tenant', 'auth:web'])->prefix('panel')->name('panel.')
            ->get('adversarial/branches/{branch:public_id}', fn (Branch $branch) => $branch->name)->name('adversarial.branch');

        $this->tenant('a')->forceFill(['status' => TenantStatus::Suspended])->save();
        $this->asTenant('a');

        $this->getJson('/panel/adversarial/branches/nonexistent')->assertStatus(402)->assertJsonPath('code', 'tenancy.suspended');
    }

    public function test_the_kernel_priority_list_orders_the_tenant_group_between_the_session_and_auth(): void
    {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $priority = $kernel->getMiddlewarePriority();
        $index = fn (string $class): int => (int) array_search($class, $priority, true);

        $this->assertLessThan($index(EnsureSessionBelongsToTenant::class), $index(StartSession::class));
        $this->assertLessThan($index(RequireTenant::class), $index(EnsureSessionBelongsToTenant::class));
        $this->assertLessThan($index(EnsureTenantIsActive::class), $index(RequireTenant::class));
        $this->assertLessThan($index(AuthenticatesRequests::class), $index(EnsureTenantIsActive::class));
        $this->assertLessThan($index(SubstituteBindings::class), $index(EnsureTenantIsActive::class));
    }
}
