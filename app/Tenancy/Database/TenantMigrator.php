<?php

declare(strict_types=1);

namespace App\Tenancy\Database;

use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Wraps the stock Migrator for database/migrations/tenant, bound to the pgsql connection; every call must run inside
 * Tenancy::run() so that hasTable('migrations') / Schema::create() act on the tenant schema (ARCHITECTURE §4.3).
 */
final class TenantMigrator
{
    /** Nesting depth of migrate()/rollback() calls; RefuseCentralWorkInsideTenant lets MigrationsStarted through only while > 0. */
    private int $running = 0;

    public function __construct(
        private readonly ConnectionResolverInterface $resolver,
        private readonly Filesystem $files,
        private readonly Dispatcher $events,
    ) {}

    /** True while this migrator runs tenant migrations (the only migrator allowed inside a tenant context). */
    public function isRunning(): bool
    {
        return $this->running > 0;
    }

    /** @return array<int, string> */
    public function paths(): array
    {
        return [database_path((string) config('tenancy.migrations_path', 'migrations/tenant'))];
    }

    /**
     * @param  array{step?: bool, pretend?: bool}  $options
     * @return array<int, string>
     */
    public function migrate(array $options = [], ?OutputInterface $output = null): array
    {
        $this->assertTenancy();
        $migrator = $this->migrator($output);

        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $this->running++;

        try {
            return $migrator->run($this->paths(), ['step' => $options['step'] ?? false, 'pretend' => $options['pretend'] ?? false]);
        } finally {
            $this->running--;
        }
    }

    /**
     * @param  array{step?: int, pretend?: bool}  $options
     * @return array<int, string>
     */
    public function rollback(array $options = [], ?OutputInterface $output = null): array
    {
        $this->assertTenancy();
        $migrator = $this->migrator($output);

        if (! $migrator->repositoryExists()) {
            return [];
        }

        $this->running++;

        try {
            return $migrator->rollback($this->paths(), ['step' => $options['step'] ?? 1, 'pretend' => $options['pretend'] ?? false]);
        } finally {
            $this->running--;
        }
    }

    /** Latest recorded batch number for the active tenant, or null when nothing ran yet. */
    public function lastBatch(): ?int
    {
        $this->assertTenancy();
        $repository = $this->repository();

        if (! $repository->repositoryExists()) {
            return null;
        }

        $batch = $repository->getLastBatchNumber();

        return $batch > 0 ? $batch : null;
    }

    /** DROP + CREATE the schema. Central context only (never dropAllTables()). */
    public function recreateSchema(Tenant $tenant): void
    {
        if (Tenancy::check()) {
            throw new InvalidArgumentException('recreateSchema() must run outside a tenant context.');
        }

        $schema = $tenant->schema_name;
        $connection = $this->resolver->connection('pgsql');
        $connection->statement("drop schema if exists \"{$schema}\" cascade");
        $connection->statement("create schema \"{$schema}\"");
    }

    public function ensureSchema(Tenant $tenant): void
    {
        $this->resolver->connection('pgsql')->statement("create schema if not exists \"{$tenant->schema_name}\"");
    }

    private function repository(): DatabaseMigrationRepository
    {
        $repository = new DatabaseMigrationRepository($this->resolver, (string) config('database.migrations.table', 'migrations'));
        $repository->setSource('pgsql');

        return $repository;
    }

    private function migrator(?OutputInterface $output = null): Migrator
    {
        $migrator = new Migrator($this->repository(), $this->resolver, $this->files, $this->events);
        $migrator->setConnection('pgsql');

        if ($output !== null) {
            $migrator->setOutput($output);
        }

        return $migrator;
    }

    private function assertTenancy(): void
    {
        if (! Tenancy::check()) {
            throw new InvalidArgumentException('TenantMigrator must run inside Tenancy::run().');
        }
    }
}
