<?php

declare(strict_types=1);

namespace App\Tenancy\Database;

use Closure;
use Illuminate\Database\PostgresConnection;
use InvalidArgumentException;
use PDO;

/**
 * The one pgsql connection. A tenant is entered by switching the Postgres session search_path to exactly
 * "tenant_<id>" and left by resetting it to exactly "public" (ARCHITECTURE §4.1). Both the live session and the
 * in-memory config array are kept in sync, and the search path is re-applied lazily after the framework replaces
 * the PDO handle (reconnect, disconnect, lost-connection retry, purge → ConnectionEstablished).
 */
final class TenantAwarePostgresConnection extends PostgresConnection
{
    private ?string $tenantSchema = null;          // null = central

    private bool $searchPathDirty = false;         // PDO replaced (or not yet opened) while a tenant was active

    /** Enter a tenant schema: live session + in-memory config, both. */
    public function setSearchPath(string $tenantSchema): void
    {
        $this->assertValidSchemaName($tenantSchema);
        $this->tenantSchema = $tenantSchema;
        $this->config['search_path'] = $tenantSchema;                  // exactly one schema
        $this->applySearchPath();
    }

    /** Leave the tenant: back to exactly 'public'. */
    public function resetSearchPath(): void
    {
        $this->tenantSchema = null;
        $this->config['search_path'] = 'public';
        $this->applySearchPath();
    }

    public function currentTenantSchema(): ?string
    {
        return $this->tenantSchema;
    }

    /**
     * DatabaseManager::refreshPdoConnections() calls this with a PDO (or lazy closure) whose session is on 'public'.
     *
     * @param  PDO|Closure|null  $pdo
     * @return $this
     */
    public function setPdo($pdo)
    {
        parent::setPdo($pdo);
        $this->searchPathDirty = $this->tenantSchema !== null && $pdo !== null;

        return $this;
    }

    /**
     * @param  PDO|Closure|null  $pdo
     * @return $this
     */
    public function setReadPdo($pdo)
    {
        parent::setReadPdo($pdo);
        $this->searchPathDirty = $this->searchPathDirty || ($this->tenantSchema !== null && $pdo !== null);

        return $this;
    }

    /**
     * Lazily re-apply after a reconnect; PDO may be a Closure until first use.
     *
     * @return PDO
     */
    public function getPdo()
    {
        $pdo = parent::getPdo();

        if ($this->searchPathDirty) {
            $this->searchPathDirty = false;
            $this->applySearchPath();
        }

        return $pdo;
    }

    private function applySearchPath(): void
    {
        if ($this->pdo === null) {
            return;                                                    // nothing connected yet; the connector sets 'public', getPdo() fixes it
        }

        if ($this->pdo instanceof Closure) {
            $this->searchPathDirty = $this->tenantSchema !== null;     // not opened yet: the connector starts on public, getPdo() applies the tenant

            return;
        }

        $path = '"'.($this->tenantSchema ?? 'public').'"';
        parent::getPdo()->exec("set search_path to {$path}");           // bypass query log/listeners

        if ($this->readPdo instanceof PDO) {
            $this->readPdo->exec("set search_path to {$path}");
        } elseif ($this->readPdo instanceof Closure) {
            $this->searchPathDirty = true;                             // resolved later through getPdo()/getReadPdo()
        }
    }

    private function assertValidSchemaName(string $schema): void
    {
        if (preg_match('/^tenant_[a-z0-9_]+$/', $schema) !== 1) {
            throw new InvalidArgumentException("Invalid tenant schema name [{$schema}].");
        }
    }
}
