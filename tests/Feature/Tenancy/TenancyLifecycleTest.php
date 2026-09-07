<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Events\TenancyEnded;
use App\Domain\Tenancy\Events\TenancyInitialized;
use App\Models\Central\PersonalAccessToken as CentralToken;
use App\Models\Tenant\PersonalAccessToken as TenantToken;
use App\Tenancy\Database\TenantAwarePostgresConnection;
use App\Tenancy\Exceptions\TenantAlreadyInitialized;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TenancyLifecycleTest extends TestCase
{
    public function test_initialize_sets_search_path_to_exactly_the_tenant_schema(): void
    {
        $this->assertSame('public', DB::scalar('show search_path'));
        $this->assertFalse(Tenancy::check());

        Tenancy::initialize($this->tenant('a'));

        $this->assertTrue(Tenancy::check());
        $this->assertSame(9001, Tenancy::id());
        $this->assertSame(self::TENANT_A, Tenancy::schema());
        $this->assertSame(self::TENANT_A, DB::scalar('show search_path'));
        $this->assertSame(self::TENANT_A, DB::connection('pgsql')->getConfig('search_path'));

        /** @var TenantAwarePostgresConnection $connection */
        $connection = DB::connection('pgsql');
        $this->assertSame(self::TENANT_A, $connection->currentTenantSchema());
    }

    public function test_end_resets_search_path_to_exactly_public(): void
    {
        Tenancy::initialize($this->tenant('a'));
        Tenancy::end();

        $this->assertFalse(Tenancy::check());
        $this->assertNull(Tenancy::id());
        $this->assertSame('public', DB::scalar('show search_path'));
        $this->assertSame('public', DB::connection('pgsql')->getConfig('search_path'));
    }

    public function test_initializing_another_tenant_throws_and_same_tenant_is_a_no_op(): void
    {
        Tenancy::initialize($this->tenant('a'));
        Tenancy::initialize($this->tenant('a'));

        $this->assertSame(9001, Tenancy::id());
        $this->expectException(TenantAlreadyInitialized::class);

        Tenancy::initialize($this->tenant('b'));
    }

    public function test_run_switches_and_restores_the_previous_tenant(): void
    {
        Tenancy::initialize($this->tenant('a'));

        $result = Tenancy::run($this->tenant('b'), function (): string {
            $this->assertSame(9002, Tenancy::id());

            return (string) DB::scalar('show search_path');
        });

        $this->assertSame(self::TENANT_B, $result);
        $this->assertSame(9001, Tenancy::id());
        $this->assertSame(self::TENANT_A, DB::scalar('show search_path'));
    }

    public function test_run_from_central_ends_tenancy_afterwards_even_on_failure(): void
    {
        try {
            Tenancy::run($this->tenant('a'), fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
    }

    public function test_initialize_swaps_permission_cache_key_sanctum_model_and_dispatches_events(): void
    {
        Event::fake([TenancyInitialized::class, TenancyEnded::class]);

        Tenancy::initialize($this->tenant('a'));

        $this->assertSame('spatie.permission.cache.'.self::TENANT_A, app(PermissionRegistrar::class)->cacheKey);
        $this->assertSame(TenantToken::class, Sanctum::$personalAccessTokenModel);
        $this->assertSame('Asia/Dhaka', config('app.display_timezone'));
        Event::assertDispatched(TenancyInitialized::class, fn (TenancyInitialized $e) => $e->tenant->id === 9001);

        Tenancy::end();

        $this->assertSame(config('permission.cache.key'), app(PermissionRegistrar::class)->cacheKey);
        $this->assertSame(CentralToken::class, Sanctum::$personalAccessTokenModel);
        Event::assertDispatched(TenancyEnded::class);
    }

    public function test_set_search_path_rejects_names_outside_the_tenant_pattern(): void
    {
        /** @var TenantAwarePostgresConnection $connection */
        $connection = DB::connection('pgsql');

        $this->expectException(\InvalidArgumentException::class);
        $connection->setSearchPath('public');
    }
}
