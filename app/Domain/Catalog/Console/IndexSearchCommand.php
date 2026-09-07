<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Console;

use App\Domain\Catalog\Search\CatalogSearchIndexer;
use Illuminate\Console\Command;

/**
 * catalog:index-search — builds catalog_drugs / catalog_icd10 through CatalogSearchIndexer (zero-downtime swap) or
 * upserts the documents a catalog version touched (--changed-since). CATALOG.md §4.4.
 */
final class IndexSearchCommand extends Command
{
    protected $signature = 'catalog:index-search {--index=all : all|catalog_drugs|catalog_icd10} {--fresh : Full rebuild via _next + swap (default when no --changed-since)} {--changed-since= : catalog_versions.version; upsert only rows touched since it}';

    protected $description = 'Build the shared Meilisearch catalog indexes';

    public function handle(CatalogSearchIndexer $indexer): int
    {
        if (config('scout.driver') !== 'meilisearch') {
            $this->components->error('scout.driver is not meilisearch (SCOUT_DRIVER); nothing to index.');

            return self::FAILURE;
        }

        $which = (string) $this->option('index');
        $indexes = match ($which) {
            'all' => [CatalogSearchIndexer::DRUGS, CatalogSearchIndexer::ICD10],
            CatalogSearchIndexer::DRUGS, CatalogSearchIndexer::ICD10 => [$which],
            default => null,
        };

        if ($indexes === null) {
            $this->components->error('--index must be all, catalog_drugs or catalog_icd10');

            return self::INVALID;
        }

        $since = $this->option('changed-since');

        if (is_string($since) && $since !== '') {
            $changed = $indexer->changedSince($since);

            if ($changed === []) {
                $this->components->error("Unknown catalog version {$since}");

                return self::FAILURE;
            }

            if (! in_array(CatalogSearchIndexer::ICD10, $indexes, true)) {
                unset($changed['icd10_codes']);
            }

            if (! in_array(CatalogSearchIndexer::DRUGS, $indexes, true)) {
                $changed = array_intersect_key($changed, ['icd10_codes' => true]);
            }

            $indexer->upsertChanged($changed);
            $this->components->info('Upserted documents changed since '.$since.': '.collect($changed)->map(fn ($ids, $t) => $t.'='.count($ids))->implode(', '));

            return self::SUCCESS;
        }

        foreach ($indexes as $index) {
            $started = hrtime(true);
            $count = $indexer->rebuild($index);
            $this->components->info(sprintf('%s: %d documents in %d ms', $indexer->uid($index), $count, (hrtime(true) - $started) / 1_000_000));
        }

        return self::SUCCESS;
    }
}
