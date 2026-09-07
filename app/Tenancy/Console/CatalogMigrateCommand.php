<?php

declare(strict_types=1);

namespace App\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Runs database/migrations/catalog on the catalog_admin connection; recorded in the catalog database's public.migrations.
 */
final class CatalogMigrateCommand extends Command
{
    protected $signature = 'catalog:migrate {--fresh : Drop every catalog table first} {--seed : Run catalog:seed afterwards} {--pretend}';

    protected $description = 'Run the catalog database migrations (catalog_admin connection)';

    public function handle(ConnectionResolverInterface $resolver, Filesystem $files, Dispatcher $events): int
    {
        if ($this->option('fresh')) {
            Schema::connection('catalog_admin')->dropAllTables();
            $this->components->info('Dropped all catalog tables.');
        }

        $repository = new DatabaseMigrationRepository($resolver, (string) config('database.migrations.table', 'migrations'));
        $repository->setSource('catalog_admin');

        $migrator = new Migrator($repository, $resolver, $files, $events);
        $migrator->setConnection('catalog_admin');
        $migrator->setOutput($this->output);

        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $path = database_path('migrations/catalog');
        $files->ensureDirectoryExists($path);

        $ran = $migrator->run([$path], ['pretend' => (bool) $this->option('pretend')]);

        if ($ran === []) {
            $this->components->info('Catalog: nothing to migrate.');
        }

        if ($this->option('seed') && ! $this->option('pretend')) {
            if (array_key_exists('catalog:seed', Artisan::all())) {
                return Artisan::call('catalog:seed', [], $this->output);
            }

            $this->components->warn('catalog:seed is not registered yet (Catalog module); skipping seed.');
        }

        return self::SUCCESS;
    }
}
