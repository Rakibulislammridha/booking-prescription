<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial;

use App\Models\Central\PersonalAccessToken as CentralToken;
use App\Models\Tenant\Branch;
use App\Tenancy\Database\TenantAwarePostgresConnection;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Octane\ResetTenancy;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Attack surface 1 (search_path leakage across Octane operations). No test transaction here: these tests open, abort
 * and roll back real transactions and purge the connection, which the RefreshDatabase wrapper cannot survive.
 */
final class SearchPathLeakTest extends TestCase
{
    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $connection = DB::connection('pgsql');

        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        Tenancy::check() && Tenancy::end();
        DB::connection('pgsql')->statement('set search_path to "public"');

        parent::tearDown();
    }

    /**
     * EXPECTED TO FAIL until fixed: a request that throws after DB::beginTransaction() (no rollback in app code) leaves
     * transactionLevel() = 1. ResetTenancy ends tenancy FIRST (SET search_path TO public inside the doomed transaction)
     * and rolls back AFTERWARDS; Postgres reverts the transactional SET, so the worker is left on the tenant schema with
     * no tenant in memory — the next central request on this worker reads tenant A's tables through every bare name.
     * Fix: in ResetTenancy roll back all open transactions before calling Tenancy::end(), and re-verify with
     * `select current_schema()` afterwards.
     */
    public function test_reset_tenancy_after_a_request_that_leaked_a_transaction_leaves_the_worker_on_public(): void
    {
        Route::middleware(['api', 'tenant'])->get('/api/adversarial/leak-transaction', function (): never {
            DB::beginTransaction();
            Branch::query()->count();

            throw new RuntimeException('boom before commit');
        });

        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test'])->getJson('/api/adversarial/leak-transaction')->assertStatus(500);
        $this->assertSame(1, DB::connection('pgsql')->transactionLevel());
        $this->assertSame(9001, Tenancy::id());

        $this->fireRequestTerminated();

        $this->assertFalse(Tenancy::check());
        $this->assertSame(0, DB::connection('pgsql')->transactionLevel());
        $this->assertSame('public', DB::scalar('show search_path'), 'ResetTenancy left the worker session on the tenant schema');
    }

    /**
     * EXPECTED TO FAIL until fixed (same root cause, worse symptom): inside an *aborted* transaction Tenancy::end()
     * throws on `set search_path` (the context is already flushed at that point), ResetTenancy swallows it, and the
     * rollback reverts the session to the tenant schema.
     */
    public function test_reset_tenancy_after_an_aborted_transaction_leaves_the_worker_on_public(): void
    {
        Tenancy::initialize($this->tenant('a'));
        DB::beginTransaction();

        try {
            DB::select('select * from adversarial_no_such_table');
        } catch (QueryException) {
            // the transaction is now aborted, exactly like a failed statement mid-request
        }

        $this->fireRequestTerminated();

        $this->assertFalse(Tenancy::check());
        $this->assertSame(0, DB::connection('pgsql')->transactionLevel());
        $this->assertSame('public', DB::scalar('show search_path'), 'ResetTenancy left the worker session on the tenant schema');
    }

    /**
     * EXPECTED TO FAIL until fixed: DB::purge('pgsql') discards the TenantAwarePostgresConnection; the next
     * DB::connection('pgsql') builds a fresh one on `public` while TenantContext still says tenant A, so every
     * tenant model query passes the guard and hits public (silently, for personal_access_tokens /
     * password_reset_tokens / migrations which exist in both schemas).
     * Fix: listen to Illuminate\Database\Events\ConnectionEstablished and re-apply the active tenant's search path.
     */
    public function test_purging_the_connection_inside_a_tenant_does_not_silently_return_to_public(): void
    {
        Tenancy::initialize($this->tenant('a'));
        $this->assertSame(self::TENANT_A, DB::scalar('show search_path'));

        DB::purge('pgsql');

        $this->assertTrue(Tenancy::check());
        $this->assertSame(self::TENANT_A, DB::scalar('show search_path'), 'tenancy is active in memory but the rebuilt connection is on public');
    }

    /** Guarantee: run() inside a transaction that rolls back keeps memory and session consistent. */
    public function test_a_rolled_back_transaction_around_tenancy_run_leaves_memory_and_session_consistent(): void
    {
        Tenancy::initialize($this->tenant('a'));

        try {
            DB::transaction(fn () => Tenancy::run($this->tenant('b'), fn () => throw new RuntimeException('boom')));
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::connection('pgsql')->transactionLevel());
        $this->assertSame(9001, Tenancy::id());
        $this->assertSame(self::TENANT_A, DB::scalar('show search_path'));
        $this->assertSame(self::TENANT_A, DB::connection('pgsql')->getConfig('search_path'));
    }

    /** Guarantee: after a normal tenant request, RequestTerminated → ResetTenancy clears every piece of tenant state. */
    public function test_reset_tenancy_after_a_normal_tenant_request_resets_every_piece_of_tenant_state(): void
    {
        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test'])->getJson('/api/ping')->assertOk();
        $this->assertSame(9001, Tenancy::id());   // left active in-process, as under Octane before RequestTerminated

        $this->fireRequestTerminated();

        /** @var TenantAwarePostgresConnection $connection */
        $connection = DB::connection('pgsql');
        $this->assertFalse(Tenancy::check());
        $this->assertNull($connection->currentTenantSchema());
        $this->assertSame('public', $connection->getConfig('search_path'));
        $this->assertSame('public', DB::scalar('show search_path'));
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertNull(config('app.display_timezone'));
        $this->assertSame(CentralToken::class, Sanctum::$personalAccessTokenModel);
        $this->assertSame(config('permission.cache.key'), app(PermissionRegistrar::class)->cacheKey);
    }

    /** Guarantee: a request that throws inside DB::transaction() (the normal idiom) leaves no transaction and resets. */
    public function test_a_request_that_throws_inside_db_transaction_is_reset_cleanly(): void
    {
        Route::middleware(['api', 'tenant'])->get('/api/adversarial/throw-in-transaction', function (): never {
            DB::transaction(function (): never {
                Branch::query()->count();

                throw new RuntimeException('boom inside transaction');
            });
        });

        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test'])->getJson('/api/adversarial/throw-in-transaction')->assertStatus(500);
        $this->assertSame(0, DB::connection('pgsql')->transactionLevel());

        $this->fireRequestTerminated();

        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
    }

    private function fireRequestTerminated(): void
    {
        (new ResetTenancy)->handle(new RequestTerminated($this->app, $this->app, Request::create('/'), new Response));
    }
}
