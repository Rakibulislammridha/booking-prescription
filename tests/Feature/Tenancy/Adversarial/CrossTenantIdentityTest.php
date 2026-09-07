<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial;

use App\Models\Central\SuperAdmin;
use App\Models\Tenant\Permission;
use App\Models\Tenant\PersonalAccessToken as TenantToken;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use Illuminate\Auth\RequestGuard;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Attack surface 3: identities that collide by id across schemas (tokens, reset tokens, permission cache, feature
 * flags, rate limits).
 */
final class CrossTenantIdentityTest extends TestCase
{
    /** Guarantee: Sanctum tokens are looked up in the active schema and compared by hash, so ids colliding is harmless. */
    public function test_a_sanctum_token_issued_in_tenant_a_is_rejected_on_tenant_b(): void
    {
        $this->asTenant('a');
        $userA = User::factory()->create();
        $plain = $userA->createToken('adversarial')->plainTextToken;
        $this->assertSame(TenantToken::class, Sanctum::$personalAccessTokenModel);
        $this->assertTrue($this->sanctumUser($plain)?->is($userA));

        $this->asTenant('b');
        $userB = User::factory()->create();
        $userB->createToken('adversarial');                                     // same token id in tenant B
        $this->assertSame((int) explode('|', $plain)[0], (int) TenantToken::query()->max('id'));

        $this->assertNull(TenantToken::findToken($plain));
        $this->assertNull($this->sanctumUser($plain));
    }

    /** Guarantee: password reset tokens live in the tenant's own password_reset_tokens table. */
    public function test_a_password_reset_token_from_tenant_a_is_invalid_in_tenant_b(): void
    {
        $this->asTenant('a');
        $userA = User::factory()->create(['email' => 'shared@both.test']);
        $token = Password::broker('users')->createToken($userA);

        $this->asTenant('b');
        $userB = User::factory()->create(['email' => 'shared@both.test']);
        $this->assertFalse(Password::broker('users')->tokenExists($userB, $token));
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'shared@both.test')->count());

        $this->asTenant('a');
        $this->assertTrue(Password::broker('users')->tokenExists($userA, $token));
    }

    /** Guarantee: spatie's permission cache is keyed per tenant schema and role→permission maps never cross. */
    public function test_spatie_permission_cache_is_keyed_per_tenant(): void
    {
        $this->asTenant('a');
        $permission = Permission::create(['name' => 'adversarial.secret', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'adversarial-role', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $userA = User::factory()->create();
        $userA->assignRole($role);

        $this->assertTrue($userA->can('adversarial.secret'));
        $keyA = app(PermissionRegistrar::class)->cacheKey;
        $this->assertSame('spatie.permission.cache.'.self::TENANT_A, $keyA);
        $this->assertTrue(Cache::has($keyA));

        $this->asTenant('b');
        $keyB = app(PermissionRegistrar::class)->cacheKey;
        $this->assertSame('spatie.permission.cache.'.self::TENANT_B, $keyB);

        $userB = User::query()->find($userA->id) ?? User::factory()->create();
        $this->assertFalse($userB->can('adversarial.secret'));
        $this->assertFalse(Permission::query()->where('name', 'adversarial.secret')->exists());
        $this->assertFalse(Role::query()->where('name', 'adversarial-role')->exists());
        $this->assertTrue(Cache::has($keyB));
        $this->assertNotEquals(Cache::get($keyA), Cache::get($keyB));
    }

    /**
     * Guarantee: Pennant's default scope is the active tenant; values are stored per tenant in public.feature_flags.
     * (Tenant implements FeatureScopeable, so resolvers receive the identifier string `tenant:{id}`, not the model.)
     */
    public function test_pennant_flags_resolve_and_store_per_tenant(): void
    {
        Feature::define('adversarial.flag', fn (string $scope): bool => $scope === 'tenant:9001');

        $this->asTenant('a');
        $this->assertTrue(Feature::active('adversarial.flag'));

        $this->asTenant('b');
        $this->assertFalse(Feature::active('adversarial.flag'));

        Feature::for($this->tenant('b'))->activate('adversarial.flag');
        $this->asTenant('b');
        $this->assertTrue(Feature::active('adversarial.flag'));
        $this->asTenant('a');
        $this->assertTrue(Feature::active('adversarial.flag'));

        $scopes = DB::table('public.feature_flags')->where('name', 'adversarial.flag')->orderBy('scope')->pluck('scope')->all();
        $this->assertSame(['tenant:9001', 'tenant:9002'], $scopes);
    }

    /**
     * EXPECTED TO FAIL until fixed (low): the `api` limiter keys by Auth::guard('sanctum')->id(), a per-schema id, so
     * user #1 of every tenant shares one bucket (one clinic can exhaust another's quota). Key by tenant id + user id.
     */
    public function test_api_rate_limit_buckets_are_not_shared_between_tenants_users_with_the_same_id(): void
    {
        $limiter = RateLimiter::limiter('api');
        $this->assertNotNull($limiter);

        $this->asTenant('a');
        $adminA = User::query()->where('email', 'admin@test-a.test')->firstOrFail();
        $this->actingAs($adminA, 'web');
        $keyA = $limiter(Request::create('http://test-a.bp.test/api/ping'))->key;

        $this->app['auth']->forgetGuards();
        $this->asTenant('b');
        $adminB = User::query()->where('email', 'admin@test-b.test')->firstOrFail();
        $this->actingAs($adminB, 'web');
        $keyB = $limiter(Request::create('http://test-b.bp.test/api/ping'))->key;

        $this->assertSame($adminA->id, $adminB->id);
        $this->assertNotSame($keyA, $keyB, "tenant A user #{$adminA->id} and tenant B user #{$adminB->id} share the api rate-limit bucket [{$keyA}]");
    }

    /**
     * EXPECTED TO FAIL until fixed (low): on a central host any session that carries a `login_web_*` marker (a tenant
     * session cookie pasted onto super.{central}) makes every consumer of the `web` guard (AuditRecorder::actor(),
     * AssignRequestId) throw TenancyNotInitialized → HTTP 500 instead of "not authenticated".
     * Fix: the staff provider should return null (not throw) when no tenant is active, or the guard should be skipped
     * centrally.
     */
    public function test_a_foreign_web_session_on_the_central_host_does_not_crash_the_request(): void
    {
        $this->asCentral();
        $super = SuperAdmin::factory()->create(['email' => 'root@bp.test', 'password' => 'secret-123']);
        $this->app['auth']->forgetGuards();

        $response = $this->withSession(['login_web_'.sha1(SessionGuard::class) => 1])
            ->post('/login', ['email' => 'root@bp.test', 'password' => 'secret-123']);

        $this->assertNotSame(500, $response->getStatusCode(), 'a stray login_web_* session key crashes the super login with '.$response->exception?->getMessage());
        $this->assertAuthenticatedAs($super, 'super');
    }

    private function sanctumUser(string $plainTextToken): ?User
    {
        $this->app['auth']->forgetGuards();
        $request = Request::create('/api/whoami', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$plainTextToken]);
        $guard = Auth::guard('sanctum');
        $this->assertInstanceOf(RequestGuard::class, $guard);
        $user = $guard->setRequest($request)->user();

        return $user instanceof User ? $user : null;
    }
}
