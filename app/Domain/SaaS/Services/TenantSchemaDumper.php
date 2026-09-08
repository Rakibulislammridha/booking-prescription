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
 * ENCRYPTION AT REST is not done here. `BackupTenant` runs the archive this class produces through
 * `App\Domain\SaaS\Services\BackupCipher` (libsodium `crypto_secretstream_xchacha20poly1305`, chunked) before it
 * reaches the `backups` disk, and `RestoreTenantBackup` decrypts back to a plaintext archive before calling
 * `restoreIntoScratch()`. This class therefore only ever sees plaintext `pg_dump` custom archives, which is what
 * `pg_dump`/`pg_restore` can actually read. (ARCHITECTURE once specified `age`; there is no `age` binary on this
 * platform and no way to build one, so the primitive that ships with PHP is used instead — ARCHITECTURE §8.2.)
 *
 * RESTORING INTO A SCRATCH SCHEMA is the awkward part. `pg_restore --schema=X` is a FILTER, not a rename: pointed
 * at the live database it recreates `tenant_<id>` objects in `tenant_<id>` — the live schema — and reports every
 * collision as an ignorable "already exists". A custom archive fully qualifies every object (`tenant_7.patients`)
 * and PostgreSQL has no "restore under a different schema name" switch, so the only way to land the archive
 * somewhere safe is to convert it to SQL (`pg_restore --file=-`), rewrite the schema identifier on the way past,
 * and feed the result to `psql --single-transaction -v ON_ERROR_STOP=1`. The rewrite is done OUTSIDE `COPY … FROM
 * stdin` data blocks only (a patient note that happens to contain the schema name is data, not an identifier), and
 * the whole thing streams: neither the archive nor the SQL is ever held in memory.
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
     * Restore a PLAINTEXT archive into a SCRATCH schema (`tenant_<id>_restore`), never over the live one. The
     * caller checks the scratch schema, then swaps — so a corrupt archive cannot destroy a working clinic.
     *
     * A failure at any point leaves no scratch schema behind: `psql --single-transaction` rolls its own work back,
     * and a `pg_restore` that dies half way (which would otherwise leave `psql` committing a truncated stream) is
     * cleaned up explicitly below.
     */
    public function restoreIntoScratch(Tenant $tenant, string $dumpPath): string
    {
        $config = $this->connection();
        $scratch = $tenant->schema_name.self::RESTORE_SUFFIX;

        $this->psql($config, 'drop schema if exists "'.$scratch.'" cascade');

        try {
            $this->pipeArchiveInto($config, $dumpPath, $tenant->schema_name, $scratch);
        } catch (RuntimeException $e) {
            $this->psql($config, 'drop schema if exists "'.$scratch.'" cascade');

            throw $e;
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

    /**
     * `pg_restore --file=- archive | <rename the schema> | psql`.
     *
     * Two children joined by a PHP loop rather than a shell pipeline, because the rewrite in the middle has to
     * understand `COPY` framing and no shell filter does. Both children write their diagnostics to files instead
     * of pipes: with only one pipe open per child there is nothing that can deadlock while we are busy pumping the
     * other one, and `psql`'s stdin gives natural back-pressure over a multi-gigabyte archive.
     *
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $config
     */
    private function pipeArchiveInto(array $config, string $dumpPath, string $from, string $to): void
    {
        $readerErrors = $this->tempFile('bp-pgrestore-err-');
        $writerOutput = $this->tempFile('bp-psql-out-');
        $writerErrors = $this->tempFile('bp-psql-err-');

        $env = ['PATH' => (string) getenv('PATH'), 'PGPASSWORD' => $config['password'], 'LC_ALL' => 'C'];

        $readerPipes = [];
        $reader = proc_open(
            ['pg_restore', '--no-owner', '--no-acl', '--file=-', $dumpPath],
            [1 => ['pipe', 'w'], 2 => ['file', $readerErrors, 'w']],
            $readerPipes,
            null,
            $env,
        );

        $writerPipes = [];
        $writer = proc_open(
            [
                'psql', '--no-psqlrc', '--quiet', '--variable=ON_ERROR_STOP=1', '--single-transaction',
                '--host='.$config['host'], '--port='.(string) $config['port'], '--username='.$config['username'],
                $config['database'],
            ],
            // 'r' is the CHILD's view of fd 0: psql reads it, so this end of the pipe is ours to write.
            [0 => ['pipe', 'r'], 1 => ['file', $writerOutput, 'w'], 2 => ['file', $writerErrors, 'w']],
            $writerPipes,
            null,
            $env,
        );

        try {
            if ($reader === false || $writer === false) {
                throw new RuntimeException('Could not start pg_restore/psql for the scratch restore.');
            }

            $readerStatus = -1;
            $writerStatus = -1;

            try {
                $this->rewriteSchema($readerPipes[1], $writerPipes[0], $from, $to);
            } finally {
                fclose($readerPipes[1]);
                fclose($writerPipes[0]);
                $readerStatus = proc_close($reader);
                $writerStatus = proc_close($writer);
            }

            if ($readerStatus !== 0) {
                throw new RuntimeException('pg_restore failed: '.$this->tail($readerErrors));
            }

            if ($writerStatus !== 0) {
                throw new RuntimeException('psql refused the restored schema: '.$this->tail($writerErrors));
            }
        } finally {
            $this->cleanUp([$readerErrors, $writerOutput, $writerErrors]);
        }
    }

    /**
     * Copy SQL from `$in` to `$out`, renaming the schema identifier everywhere it is an identifier and nowhere it
     * is data. `COPY … FROM stdin;` opens a block that ends at a line holding exactly `\.` (the format escapes any
     * such line inside the data), and nothing between the two is touched.
     *
     * @param  resource  $in
     * @param  resource  $out
     */
    private function rewriteSchema($in, $out, string $from, string $to): void
    {
        $identifier = '/(?<![A-Za-z0-9_])'.preg_quote($from, '/').'(?![A-Za-z0-9_])/';
        $inCopyData = false;

        while (($line = fgets($in)) !== false) {
            if ($inCopyData) {
                $inCopyData = rtrim($line, "\r\n") !== '\.';
            } else {
                $line = (string) preg_replace($identifier, $to, $line);
                $inCopyData = preg_match('/^COPY .* FROM stdin;\r?\n?$/', $line) === 1;
            }

            $offset = 0;
            $length = strlen($line);

            while ($offset < $length) {
                $written = fwrite($out, substr($line, $offset));

                if ($written === false || $written === 0) {
                    throw new RuntimeException('psql stopped reading the restore stream.');
                }

                $offset += $written;
            }
        }
    }

    /** @param  array{host: string, port: int, database: string, username: string, password: string}  $config */
    private function psql(array $config, string $sql): void
    {
        $process = new Process([
            'psql', '--no-psqlrc', '--quiet', '--variable=ON_ERROR_STOP=1', '--command='.$sql,
            '--host='.$config['host'], '--port='.(string) $config['port'], '--username='.$config['username'], $config['database'],
        ], null, ['PGPASSWORD' => $config['password']], null, 120.0);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('psql failed: '.mb_substr($process->getErrorOutput(), 0, 500));
        }
    }

    private function tempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file for the scratch restore.');
        }

        return $path;
    }

    private function tail(string $path): string
    {
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        return mb_substr(trim($contents), -500) ?: 'no diagnostics';
    }

    /** @param  array<int, string>  $paths */
    private function cleanUp(array $paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }
}
