<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Search;

use App\Models\Tenant\CustomBrand;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Postgres ILIKE fallback used when scout.driver is not meilisearch (tests, dev without the index). Produces the
 * same documents as the indexes with a synthetic _rankingScore so DrugSearchService re-ranks identically.
 */
final class DatabaseDrugSearch
{
    public function __construct(private readonly CatalogSearchIndexer $indexer) {}

    /** @return list<array<string, mixed>> */
    public function hits(string $q, ?float $strengthMg, int $limit): array
    {
        $needle = mb_strtolower(trim($q));

        if ($needle === '') {
            return [];
        }

        $prefix = self::escape($needle).'%';
        $contains = '%'.self::escape($needle).'%';
        $catalog = DB::connection('catalog');

        $brands = $catalog->table('brands AS b')->join('generics AS g', 'g.id', '=', 'b.generic_id')
            ->where('b.is_active', true)->where('g.is_active', true)->where('g.needs_review', false)
            ->where(function ($w) use ($prefix, $contains, $needle): void {
                $w->whereRaw('lower(b.name) LIKE ?', [$prefix])
                    ->orWhereRaw('lower(g.name) LIKE ?', [$prefix])
                    ->orWhereRaw('lower(b.name) LIKE ?', [$contains])
                    ->orWhereRaw('EXISTS (SELECT 1 FROM jsonb_array_elements_text(b.aliases) a WHERE lower(a) LIKE ?)', [$prefix])
                    ->orWhereRaw('EXISTS (SELECT 1 FROM jsonb_array_elements_text(g.aliases) a WHERE lower(a) LIKE ?)', [$prefix])
                    ->orWhereRaw('lower(g.name) = ?', [$needle]);
            })
            ->orderByDesc('b.popularity')->limit(60)->pluck('b.id')->map(fn ($v) => (int) $v)->all();

        $docs = [];

        if ($brands !== []) {
            $strengths = $catalog->table('strengths')->whereIn('brand_id', $brands)->where('is_active', true);

            if ($strengthMg !== null) {
                $strengths->where('strength_mg', $strengthMg);
            }

            $ids = $strengths->pluck('id')->map(fn ($v) => (int) $v)->all();

            foreach ($this->indexer->presentationDocuments($ids) as $doc) {
                $doc['_rankingScore'] = $this->score($needle, $doc);
                $doc['_federation'] = ['indexUid' => $this->indexer->uid(CatalogSearchIndexer::DRUGS)];
                $docs[] = $doc;
            }
        }

        $genericIds = $catalog->table('generics')->where('is_active', true)->where('needs_review', false)
            ->where(function ($w) use ($prefix): void {
                $w->whereRaw('lower(name) LIKE ?', [$prefix])
                    ->orWhereRaw('EXISTS (SELECT 1 FROM jsonb_array_elements_text(aliases) a WHERE lower(a) LIKE ?)', [$prefix]);
            })->limit(10)->pluck('id')->map(fn ($v) => (int) $v)->all();

        if ($genericIds !== [] && $strengthMg === null) {
            foreach ($this->indexer->genericDocuments($genericIds) as $doc) {
                $doc['_rankingScore'] = $this->score($needle, $doc);
                $doc['_federation'] = ['indexUid' => $this->indexer->uid(CatalogSearchIndexer::DRUGS)];
                $docs[] = $doc;
            }
        }

        if (Tenancy::check()) {
            $custom = CustomBrand::query()->usable()
                ->where(function ($w) use ($prefix, $contains): void {
                    $w->whereRaw('lower(brand_name) LIKE ?', [$prefix])->orWhereRaw('lower(generic_name) LIKE ?', [$prefix])->orWhereRaw('lower(brand_name) LIKE ?', [$contains]);
                })
                ->orderByDesc('use_count')->limit(20)->get();

            foreach ($custom as $brand) {
                $doc = $brand->toSearchableArray();

                if ($strengthMg !== null && $doc['strength_mg'] !== $strengthMg) {
                    continue;
                }

                $doc['_rankingScore'] = $this->score($needle, $doc);
                $doc['_federation'] = ['indexUid' => (new CustomBrand)->searchableAs()];
                $docs[] = $doc;
            }
        }

        usort($docs, fn ($a, $b) => [$b['_rankingScore'], $b['popularity']] <=> [$a['_rankingScore'], $a['popularity']]);

        return array_slice($docs, 0, max($limit * 3, 40));
    }

    /**
     * Approximates Meilisearch: exact brand 1.0, brand prefix 0.95, generic prefix 0.85, alias 0.8, contains 0.6.
     *
     * @param  array<string, mixed>  $doc
     */
    private function score(string $needle, array $doc): float
    {
        $brand = mb_strtolower((string) ($doc['brand_name'] ?? ''));
        $generic = mb_strtolower((string) ($doc['generic_name'] ?? ''));

        if ($brand === $needle || $generic === $needle) {
            return 1.0;
        }

        if ($brand !== '' && str_starts_with($brand, $needle)) {
            return 0.95;
        }

        if (str_starts_with($generic, $needle)) {
            return 0.85;
        }

        foreach ([...($doc['brand_aliases'] ?? []), ...($doc['generic_aliases'] ?? [])] as $alias) {
            if (str_starts_with(mb_strtolower((string) $alias), $needle)) {
                return 0.8;
            }
        }

        return 0.6;
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
