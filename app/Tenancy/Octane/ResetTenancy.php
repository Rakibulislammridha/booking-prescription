<?php

declare(strict_types=1);

namespace App\Tenancy\Octane;

use App\Tenancy\Database\TenantAwarePostgresConnection;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * RequestTerminated / TaskTerminated / TickTerminated: always leave the worker on 'public' with no open transaction.
 *
 * Order matters: SET search_path is transactional in Postgres, so a leaked (or aborted) transaction is rolled back
 * FIRST — ending the tenancy inside it would either throw (aborted) or be reverted by the rollback (leaked).
 * The live session is then verified with `select current_schema()`.
 */
final class ResetTenancy
{
    public function handle(object $event): void
    {
        /** @var Application $app */
        $app = property_exists($event, 'sandbox') ? $event->sandbox : $event->app;

        /** @var DatabaseManager $db */
        $db = $app->make('db');
        $connection = $db->connection('pgsql');

        if ($connection->transactionLevel() > 0) {
            Log::critical('tenancy.leaked_transaction', ['level' => $connection->transactionLevel()]);

            while ($connection->transactionLevel() > 0) {
                try {
                    $connection->rollBack();
                } catch (Throwable $e) {
                    Log::critical('tenancy.rollback_failed', ['exception' => $e->getMessage()]);
                    $connection->disconnect();                         // a fresh session starts on public
                    break;
                }
            }
        }

        try {
            $app->make(Tenancy::class)->end();
        } catch (Throwable $e) {
            Log::critical('tenancy.reset_failed', ['exception' => $e->getMessage()]);
        }

        if ($connection instanceof TenantAwarePostgresConnection && $connection->currentTenantSchema() !== null) {
            Log::critical('tenancy.leak', ['schema' => $connection->currentTenantSchema()]);
            $connection->resetSearchPath();
        }

        $this->verifyLiveSession($connection);
    }

    /** Only a connected session is checked; a lazy (not yet opened) PDO starts on public anyway. */
    private function verifyLiveSession(Connection $connection): void
    {
        if (! $connection->getRawPdo() instanceof PDO) {
            return;
        }

        try {
            $schema = $connection->scalar('select current_schema()');
        } catch (Throwable $e) {
            Log::critical('tenancy.verify_failed', ['exception' => $e->getMessage()]);
            $connection->disconnect();

            return;
        }

        if ($schema !== 'public') {
            Log::critical('tenancy.leak', ['live_schema' => $schema]);

            if ($connection instanceof TenantAwarePostgresConnection) {
                $connection->resetSearchPath();
            } else {
                $connection->disconnect();
            }
        }
    }
}
