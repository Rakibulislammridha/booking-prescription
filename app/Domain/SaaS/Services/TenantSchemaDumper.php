<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Models\Central\Tenant;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * `pg_dump`/`pg_restore` for exactly one tenant schema (ARCHITECTURE §8.8).
 *
 * `-n "tenant_<id>"` is the whole isolation story: a backup of clinic A cannot contain a row of clinic B, because
 * the dump is scoped to a schema and the schema is the boundary. The password goes through `PGPASSWORD` in the
 * child's environment rather than a connection string, so it never reaches a process list or a log line.
 *
 * The dump is written to a local temp file first: streaming a custom-format dump straight to an S3 disk would
 * need the whole thing in memory, and a clinic with three years of serials is not small.
 *
 * NOTE — encryption at rest. ARCHITECTURE §8.8 specifies `age`-encrypting the dump before upload. The binary is
 * not present in this environment and the platform has no key pair yet, so dumps are uploaded unencrypted to the
 * `backups` disk and `[foundation]` carries the request for the binary and a recipient key. The seam is
 * `encryptInto()`: one implementation change, no call-site change.
 */
final class TenantSchemaDumper
{
    public const RESTORE_SUFFIX = '_restore';

    /** @return array{path: string, bytes: int, sha256: string} */
    public function dump(Tenant $tenant, string $destination): array
    {
        $config = $this->connection();

        $process = new Process([
            'pg_dump',
            '--format=custom',
            '--no-owner',
            '--no-acl',
            '--schema='.$tenant->schema_name,
            '--file='.$destination,
            '--host='.$config['host'],
            '--port='.(string) $config['port'],
            '--username='.$config['username'],
            $config['database'],
        ], null, ['PGPASSWORD' => $config['password']], null, 600.0);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('pg_dump failed: '.mb_substr($process->getErrorOutput(), 0, 500));
        }

        if (! is_file($destination)) {
            throw new RuntimeException('pg_dump produced no file');
        }

        return [
            'path' => $destination,
            'bytes' => (int) filesize($destination),
            'sha256' => (string) hash_file('sha256', $destination),
        ];
    }

    /**
     * Restore a dump into a SCRATCH schema (`tenant_<id>_restore`), never over the live one. The caller checks
     * the scratch schema, then swaps — so a corrupt archive cannot destroy a working clinic.
     */
    public function restoreIntoScratch(Tenant $tenant, string $dumpPath): string
    {
        $config = $this->connection();
        $scratch = $tenant->schema_name.self::RESTORE_SUFFIX;

        $this->psql($config, 'drop schema if exists "'.$scratch.'" cascade');

        $process = new Process([
            'pg_restore',
            '--no-owner',
            '--no-acl',
            '--schema='.$tenant->schema_name,
            // Rewrites `tenant_7` → `tenant_7_restore` for everything the dump creates.
            '--dbname='.$config['database'],
            '--host='.$config['host'],
            '--port='.(string) $config['port'],
            '--username='.$config['username'],
            $dumpPath,
        ], null, ['PGPASSWORD' => $config['password']], null, 600.0);

        $process->run();

        if (! $process->isSuccessful() && ! str_contains($process->getErrorOutput(), 'already exists')) {
            throw new RuntimeException('pg_restore failed: '.mb_substr($process->getErrorOutput(), 0, 500));
        }

        return $scratch;
    }

    /** @return array{host: string, port: int, database: string, username: string, password: string} */
    public function connection(): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('database.connections.pgsql');

        return [
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => (int) ($config['port'] ?? 5432),
            'database' => (string) ($config['database'] ?? 'booking'),
            'username' => (string) ($config['username'] ?? 'postgres'),
            'password' => (string) ($config['password'] ?? ''),
        ];
    }

    /** @param  array{host: string, port: int, database: string, username: string, password: string}  $config */
    private function psql(array $config, string $sql): void
    {
        $process = new Process([
            'psql', '--no-psqlrc', '--quiet', '--command='.$sql,
            '--host='.$config['host'], '--port='.(string) $config['port'], '--username='.$config['username'], $config['database'],
        ], null, ['PGPASSWORD' => $config['password']], null, 120.0);

        $process->run();
    }
}
