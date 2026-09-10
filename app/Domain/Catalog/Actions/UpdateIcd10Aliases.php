<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Search\CatalogSearchIndexer;
use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Models\Catalog\Icd10Code;

/**
 * The plain-language aliases of an ICD-10 code (`"sugar"`, `"ডায়াবেটিস"`) — what makes a doctor's shorthand find
 * E11.9 (CATALOG.md §3.3). They are search data, not clinical structure, so the console may edit them; the code,
 * title and hierarchy stay the importer's. Goes through CatalogWriteContext and re-upserts the ICD-10 document.
 */
final class UpdateIcd10Aliases
{
    public function __construct(
        private readonly CatalogWriteContext $context,
        private readonly CatalogCache $cache,
    ) {}

    /**
     * @param  list<string>  $aliases
     * @return array{model: Icd10Code, before: array<string, mixed>, after: array<string, mixed>}
     */
    public function handle(int $id, array $aliases, ?string $titleBn = null): array
    {
        $clean = array_values(array_unique(array_filter(array_map(fn (string $a) => trim($a), $aliases), fn (string $a) => $a !== '')));

        /** @var array{model: Icd10Code, before: array<string, mixed>, after: array<string, mixed>} $result */
        $result = $this->context->run(function () use ($id, $clean, $titleBn): array {
            $row = Icd10Code::query()->findOrFail($id);
            $before = ['aliases' => (array) $row->aliases, 'title_bn' => $row->title_bn];
            $attributes = ['aliases' => $clean];

            if ($titleBn !== null) {
                $attributes['title_bn'] = trim($titleBn) === '' ? null : trim($titleBn);
            }

            $row->forceFill($attributes)->save();

            return ['model' => $row, 'before' => $before, 'after' => ['aliases' => $clean, 'title_bn' => $row->title_bn]];
        }, 'console:icd10-aliases');

        $this->cache->bumpVersion();

        if (config('scout.driver') === 'meilisearch') {
            app(CatalogSearchIndexer::class)->upsertChanged(['icd10_codes' => [$id]]);
        }

        return $result;
    }
}
