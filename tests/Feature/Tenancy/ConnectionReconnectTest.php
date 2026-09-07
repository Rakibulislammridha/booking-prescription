<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Tenancy\Database\TenantAwarePostgresConnection;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reconnects replace the PDO with a session on 'public' (DatabaseManager::refreshPdoConnections); the tenant
 * search path must be re-applied lazily. No transaction here: disconnecting would kill it.
 */
final class ConnectionReconnectTest extends TestCase
{
    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    public function test_search_path_is_reapplied_after_disconnect_and_lazy_reconnect(): void
    {
        $this->asTenant('a');
        $this->assertSame(self::TENANT_A, DB::scalar('show search_path'));

        DB::connection('pgsql')->disconnect();

        $this->assertSame(self::TENANT_A, DB::scalar('show search_path'));
        $this->assertSame(self::TENANT_A, DB::connection('pgsql')->getConfig('search_path'));
    }

    public function test_search_path_is_reapplied_after_an_explicit_manager_reconnect(): void
    {
        $this->asTenant('b');

        DB::reconnect('pgsql');

        $this->assertSame(self::TENANT_B, DB::scalar('show search_path'));
        $this->assertSame(1, DB::table('branches')->count());
    }

    public function test_central_reconnect_stays_on_public(): void
    {
        $this->assertFalse(Tenancy::check());
        DB::reconnect('pgsql');

        $this->assertSame('public', DB::scalar('show search_path'));

        /** @var TenantAwarePostgresConnection $connection */
        $connection = DB::connection('pgsql');
        $this->assertNull($connection->currentTenantSchema());
    }

    public function test_end_after_reconnect_resets_the_new_session_too(): void
    {
        $this->asTenant('a');
        DB::connection('pgsql')->disconnect();
        Tenancy::end();

        $this->assertSame('public', DB::scalar('show search_path'));
    }
}
