<?php

declare(strict_types=1);

namespace Database\Seeders\Catalog;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Development sample (CATALOG.md §3). Inserts nothing itself: it runs
 * `catalog:import database/data/catalog --source=seed --version=dev.<timestamp>` so the seed goes through the importer.
 */
final class CatalogSampleSeeder extends Seeder
{
    public function run(bool $force = false, bool $reindex = false): int
    {
        $path = (string) config('catalog.seed_path', database_path('data/catalog'));

        $options = [
            'path' => $path,
            '--source' => 'seed',
            '--catalog-version' => 'dev.'.now()->format('Ymd-His'),
            '--force' => $force,
            '--reindex' => $reindex,
        ];

        return Artisan::call('catalog:import', $options, $this->command->getOutput());
    }
}
