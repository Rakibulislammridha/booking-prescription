<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Console;

use Illuminate\Console\Command;

/**
 * catalog:seed — runs Database\Seeders\Catalog\CatalogSampleSeeder, which delegates to catalog:import so the dev sample
 * exercises the same code path as a DGDA update (CATALOG.md §3, ARCHITECTURE.md §4.3). Found by catalog:migrate --seed.
 */
final class SeedCommand extends Command
{
    protected $signature = 'catalog:seed {--class=CatalogSampleSeeder : Seeder class under Database\\Seeders\\Catalog} {--force : Re-import even if the bundle checksum is known} {--reindex : Rebuild the Meilisearch indexes afterwards}';

    protected $description = 'Import the bundled development catalog sample through the import pipeline';

    public function handle(): int
    {
        $class = (string) $this->option('class');
        $fqcn = str_contains($class, '\\') ? $class : 'Database\\Seeders\\Catalog\\'.$class;

        if (! class_exists($fqcn)) {
            $this->components->error("Seeder {$fqcn} does not exist.");

            return self::INVALID;
        }

        $seeder = app($fqcn);
        $seeder->setContainer(app())->setCommand($this);

        return (int) ($seeder->__invoke(['force' => (bool) $this->option('force'), 'reindex' => (bool) $this->option('reindex')]) ?? self::SUCCESS);
    }
}
