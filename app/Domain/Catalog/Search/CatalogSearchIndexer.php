<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Search;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;

/**
 * Builds the shared catalog_drugs / catalog_icd10 indexes directly (they are joins, not Scout models — CATALOG.md §4).
 * Every uid carries config('scout.prefix'); rebuilds go through {uid}_next + an atomic swap so search never sees an
 * empty index. Documents are never deleted: inactive ones carry is_active=false and are filtered by the query.
 */
final class CatalogSearchIndexer
{
    public const DRUGS = 'catalog_drugs';

    public const ICD10 = 'catalog_icd10';

    public function __construct(private readonly Client $client) {}

    public function uid(string $index): string
    {
        return config('scout.prefix').$index;
    }

    /** @return array<string, mixed> */
    public function settings(string $index): array
    {
        return (array) config("catalog.search.{$index}");
    }

    public function rebuildAll(): void
    {
        $this->rebuild(self::DRUGS);
        $this->rebuild(self::ICD10);
    }

    /** Zero-downtime rebuild: {uid}_next gets settings + documents, then swaps with {uid} (CATALOG.md §4.4). */
    public function rebuild(string $index): int
    {
        $uid = $this->uid($index);
        $next = $uid.'_next';

        $this->ensureIndex($uid);
        $this->deleteIfExists($next);
        $this->wait($this->client->createIndex($next, ['primaryKey' => 'id']));
        $this->wait($this->client->index($next)->updateSettings($this->settings($index)));

        $count = 0;
        $last = null;

        foreach ($this->documents($index)->chunk(2000) as $chunk) {
            $docs = $chunk->values()->all();
            $count += count($docs);
            $tasks = $this->client->index($next)->addDocumentsInBatches($docs, 2000, 'id');
            $last = end($tasks) ?: $last;
        }

        if ($last !== null) {
            self::assertSucceeded($this->client->waitForTask($last['taskUid'], 600_000, 200));
        }

        $this->wait($this->client->swapIndexes([[$uid, $next]]));
        $this->wait($this->client->deleteIndex($next));

        return $count;
    }

    /**
     * Incremental upsert into the live index (settings re-applied so a fresh index behaves identically).
     *
     * @param  iterable<array<string, mixed>>  $docs
     */
    public function upsert(string $index, iterable $docs): void
    {
        $uid = $this->uid($index);
        $this->ensureIndex($uid, applySettings: true);
        $list = is_array($docs) ? $docs : iterator_to_array($docs, false);

        if ($list === []) {
            return;
        }

        $tasks = $this->client->index($uid)->updateDocumentsInBatches(array_values($list), 2000, 'id');
        $last = end($tasks);

        if ($last !== false) {
            self::assertSucceeded($this->client->waitForTask($last['taskUid'], 600_000, 200));
        }
    }

    /**
     * Re-index the documents an import touched (catalog:index-search --changed-since / after promotion).
     *
     * @param  array<string, list<int>>  $changed  table → ids (generics, brands, strengths, icd10_codes)
     */
    public function upsertChanged(array $changed): void
    {
        $strengthIds = $changed['strengths'] ?? [];
        $genericIds = $changed['generics'] ?? [];
        $brandIds = $changed['brands'] ?? [];

        if ($brandIds !== []) {
            $strengthIds = [...$strengthIds, ...DB::connection('catalog')->table('strengths')->whereIn('brand_id', $brandIds)->pluck('id')->map(fn ($v) => (int) $v)->all()];
        }

        if ($genericIds !== []) {
            $strengthIds = [...$strengthIds, ...DB::connection('catalog')->table('strengths')->whereIn('generic_id', $genericIds)->pluck('id')->map(fn ($v) => (int) $v)->all()];
        }

        $strengthIds = array_values(array_unique($strengthIds));
        $docs = [];

        if ($strengthIds !== []) {
            $genericIds = array_values(array_unique([...$genericIds, ...DB::connection('catalog')->table('strengths')->whereIn('id', $strengthIds)->pluck('generic_id')->map(fn ($v) => (int) $v)->all()]));
            $docs = $this->presentationDocuments($strengthIds)->all();
        }

        if ($genericIds !== []) {
            $docs = [...$docs, ...$this->genericDocuments($genericIds)->all()];
        }

        if ($docs !== []) {
            $this->upsert(self::DRUGS, $docs);
        }

        if (($changed['icd10_codes'] ?? []) !== []) {
            $this->upsert(self::ICD10, $this->icd10Documents($changed['icd10_codes'])->all());
        }
    }

    /**
     * Ids touched by a catalog version (catalog:index-search --changed-since={version}).
     *
     * @return array<string, list<int>>
     */
    public function changedSince(string $version): array
    {
        $row = DB::connection('catalog')->table('catalog_versions')->where('version', $version)->first(['id']);

        if ($row === null) {
            return [];
        }

        $out = [];

        foreach (['generics', 'brands', 'strengths', 'icd10_codes'] as $table) {
            $out[$table] = DB::connection('catalog')->table($table)->where('catalog_version_id', '>=', $row->id)->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        return $out;
    }

    /** @return LazyCollection<int, array<string, mixed>> */
    public function documents(string $index): LazyCollection
    {
        return match ($index) {
            self::DRUGS => LazyCollection::make(function () {
                foreach ($this->presentationDocuments() as $doc) {
                    yield $doc;                                           // re-keyed: chunk() would merge equal generator keys
                }

                foreach ($this->genericDocuments() as $doc) {
                    yield $doc;
                }
            }),
            self::ICD10 => $this->icd10Documents(),
            default => throw new \InvalidArgumentException("Unknown catalog index {$index}"),
        };
    }

    /**
     * s{strength_id} documents: strengths ⋈ brands ⋈ generics ⋈ dosage_forms ⋈ routes (+ drug_information slug).
     *
     * @param  list<int>|null  $strengthIds
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function presentationDocuments(?array $strengthIds = null): LazyCollection
    {
        $query = DB::connection('catalog')->table('strengths AS s')
            ->join('brands AS b', 'b.id', '=', 's.brand_id')
            ->join('generics AS g', 'g.id', '=', 's.generic_id')
            ->join('dosage_forms AS f', 'f.id', '=', 's.dosage_form_id')
            ->leftJoin('routes AS r', 'r.id', '=', 's.route_id')
            ->leftJoin('drug_information AS di', 'di.generic_id', '=', 'g.id')
            ->where('g.needs_review', false)
            ->orderBy('s.id')
            ->select([
                's.id AS strength_id', 's.strength_label', 's.strength_value', 's.strength_unit', 's.per_volume_ml', 's.strength_mg', 's.per_ml',
                's.pack_size', 's.pack_size_value', 's.pack_unit', 's.is_active AS s_active',
                'b.id AS brand_id', 'b.name AS brand_name', 'b.aliases AS brand_aliases', 'b.manufacturer', 'b.popularity', 'b.is_active AS b_active',
                'g.id AS generic_id', 'g.name AS generic_name', 'g.aliases AS generic_aliases', 'g.therapeutic_class', 'g.is_controlled', 'g.is_active AS g_active',
                'f.id AS dosage_form_id', 'f.name AS form', 'f.abbreviation AS form_abbr', 'f.code AS form_code', 'f.default_unit',
                'r.id AS route_id', 'r.name AS route', 'r.code AS route_code', 'di.public_slug AS info_slug',
            ]);

        if ($strengthIds !== null) {
            $query->whereIn('s.id', $strengthIds);
        }

        return LazyCollection::make(function () use ($query) {
            foreach ($query->cursor() as $row) {
                yield $this->presentationDocument($row);
            }
        });
    }

    /**
     * g{generic_id} documents ("prescribe by generic"): popularity = max(brand popularity) + 10.
     *
     * @param  list<int>|null  $genericIds
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function genericDocuments(?array $genericIds = null): LazyCollection
    {
        $query = DB::connection('catalog')->table('generics AS g')
            ->leftJoin('brands AS b', 'b.generic_id', '=', 'g.id')
            ->leftJoin('drug_information AS di', 'di.generic_id', '=', 'g.id')
            ->where('g.needs_review', false)
            ->groupBy('g.id', 'g.name', 'g.aliases', 'g.therapeutic_class', 'g.is_controlled', 'g.is_active', 'di.public_slug')
            ->orderBy('g.id')
            ->selectRaw('g.id, g.name, g.aliases, g.therapeutic_class, g.is_controlled, g.is_active, di.public_slug, COALESCE(MAX(b.popularity), 0) AS max_popularity');

        if ($genericIds !== null) {
            $query->whereIn('g.id', $genericIds);
        }

        return LazyCollection::make(function () use ($query) {
            foreach ($query->cursor() as $g) {
                yield [
                    'id' => 'g'.$g->id, 'source' => 'master', 'doc_type' => 'generic', 'label' => $g->name.' (any brand)',
                    'generic_id' => (int) $g->id, 'generic_name' => $g->name, 'generic_aliases' => self::json($g->aliases),
                    'brand_id' => null, 'custom_brand_id' => null, 'brand_name' => null, 'brand_aliases' => [], 'manufacturer' => null,
                    'strength_id' => null, 'strength_label' => null, 'strength_value' => null, 'strength_unit' => null, 'per_volume_ml' => null,
                    'strength_mg' => null, 'per_ml' => null, 'dosage_form_id' => null, 'form' => null, 'form_code' => null, 'default_unit' => null,
                    'route_id' => null, 'route' => null, 'route_code' => null, 'pack_size' => null, 'pack_size_value' => null, 'pack_unit' => null,
                    'info_slug' => $g->public_slug, 'therapeutic_class' => $g->therapeutic_class, 'is_controlled' => (bool) $g->is_controlled,
                    'popularity' => (int) $g->max_popularity + 10, 'is_active' => (bool) $g->is_active,
                ];
            }
        });
    }

    /**
     * Active ICD-10 codes with plain-language aliases; popularity from config('catalog.search.icd10_popularity').
     *
     * @param  list<int>|null  $ids
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function icd10Documents(?array $ids = null): LazyCollection
    {
        $popularity = (array) config('catalog.search.icd10_popularity', []);
        $query = DB::connection('catalog')->table('icd10_codes')->where('is_active', true)->orderBy('id');

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return LazyCollection::make(function () use ($query, $popularity) {
            foreach ($query->cursor() as $c) {
                yield [
                    'id' => self::icd10DocumentId($c->code), 'code' => $c->code, 'parent_code' => $c->parent_code, 'chapter' => $c->chapter, 'block' => $c->block,
                    'title' => $c->title, 'title_bn' => $c->title_bn, 'aliases' => self::json($c->aliases), 'is_billable' => (bool) $c->is_billable,
                    'popularity' => (int) ($popularity[$c->code] ?? 0),
                ];
            }
        });
    }

    /** @return array<string, mixed> */
    private function presentationDocument(object $row): array
    {
        return [
            'id' => 's'.$row->strength_id, 'source' => 'master', 'doc_type' => 'presentation',
            'label' => trim("{$row->brand_name} {$row->strength_label} {$row->form_abbr}"),
            'generic_id' => (int) $row->generic_id, 'generic_name' => $row->generic_name, 'generic_aliases' => self::json($row->generic_aliases),
            'brand_id' => (int) $row->brand_id, 'custom_brand_id' => null, 'brand_name' => $row->brand_name, 'brand_aliases' => self::json($row->brand_aliases),
            'manufacturer' => $row->manufacturer,
            'strength_id' => (int) $row->strength_id, 'strength_label' => $row->strength_label,
            'strength_value' => self::num($row->strength_value), 'strength_unit' => $row->strength_unit, 'per_volume_ml' => self::num($row->per_volume_ml),
            'strength_mg' => self::num($row->strength_mg), 'per_ml' => self::num($row->per_ml),
            'dosage_form_id' => (int) $row->dosage_form_id, 'form' => $row->form, 'form_code' => $row->form_code, 'default_unit' => $row->default_unit,
            'route_id' => $row->route_id === null ? null : (int) $row->route_id, 'route' => $row->route, 'route_code' => $row->route_code,
            'pack_size' => $row->pack_size, 'pack_size_value' => self::num($row->pack_size_value), 'pack_unit' => $row->pack_unit,
            'info_slug' => $row->info_slug, 'therapeutic_class' => $row->therapeutic_class, 'is_controlled' => (bool) $row->is_controlled,
            'popularity' => (int) $row->popularity,
            'is_active' => (bool) $row->s_active && (bool) $row->b_active && (bool) $row->g_active,
        ];
    }

    private function ensureIndex(string $uid, bool $applySettings = false): void
    {
        try {
            $this->client->getIndex($uid);
        } catch (ApiException $e) {
            if ($e->errorCode !== 'index_not_found') {
                throw $e;
            }

            $this->wait($this->client->createIndex($uid, ['primaryKey' => 'id']));
            $applySettings = true;
        }

        if ($applySettings) {
            $index = str_starts_with($uid, config('scout.prefix')) ? substr($uid, strlen((string) config('scout.prefix'))) : $uid;
            $this->wait($this->client->index($uid)->updateSettings($this->settings($index)));
        }
    }

    private function deleteIfExists(string $uid): void
    {
        $task = $this->client->waitForTask($this->client->deleteIndex($uid)['taskUid'], 120_000, 100);   // a missing index fails asynchronously

        if (($task['status'] ?? null) === 'failed' && ($task['error']['code'] ?? null) !== 'index_not_found') {
            self::assertSucceeded($task);
        }
    }

    /** Meilisearch document ids allow only [A-Za-z0-9_-]: `E11.9` → `E11_9` (the `code` field keeps the dot). */
    public static function icd10DocumentId(string $code): string
    {
        return str_replace('.', '_', $code);
    }

    /** @param  array<string, mixed>  $task */
    private function wait(array $task): void
    {
        self::assertSucceeded($this->client->waitForTask($task['taskUid'], 120_000, 100));
    }

    /** @param  array<string, mixed>  $task */
    private static function assertSucceeded(array $task): void
    {
        if (($task['status'] ?? null) === 'failed') {
            throw new \RuntimeException('Meilisearch task '.($task['uid'] ?? '?').' failed: '.($task['error']['message'] ?? 'unknown error'));
        }
    }

    /** @return list<string> */
    private static function json(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private static function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
