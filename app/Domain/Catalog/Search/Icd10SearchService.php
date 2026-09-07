<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Search;

use Illuminate\Support\Facades\DB;
use Meilisearch\Client;

/**
 * ICD-10 autocomplete over catalog_icd10 (PRESCRIPTION.md §3.4): colloquial aliases and synonyms in both scripts, the
 * doctor's own recent codes first. Postgres fallback when Meilisearch is not the driver.
 */
final class Icd10SearchService
{
    public function __construct(
        private readonly Client $client,
        private readonly CatalogSearchIndexer $indexer,
        private readonly DoctorUsageBoostProvider $boosts,
    ) {}

    /** @return array{q: string, took_ms: int, hits: list<array<string, mixed>>, engine: string} */
    public function search(string $q, int $limit = 10, ?int $doctorId = null, bool $billableOnly = true): array
    {
        $started = hrtime(true);
        $q = trim($q);

        if ($q === '') {
            return ['q' => $q, 'took_ms' => 0, 'hits' => [], 'engine' => 'none'];
        }

        $meili = config('scout.driver') === 'meilisearch';
        $raw = $meili ? $this->meilisearch($q, $limit, $billableOnly) : $this->database($q, $limit, $billableOnly);
        $usage = $doctorId !== null ? $this->boosts->icdUsage($doctorId) : [];
        $hits = [];

        foreach ($raw as $doc) {
            $doc['usage'] = (int) ($usage[$doc['code']] ?? 0);
            $doc['score'] = round(((float) ($doc['_rankingScore'] ?? 0.5)) * 1000 + min($doc['usage'], 200) * 2, 1);
            unset($doc['_rankingScore']);
            $hits[] = $doc;
        }

        usort($hits, fn ($a, $b) => [$b['score'], (int) ($b['popularity'] ?? 0)] <=> [$a['score'], (int) ($a['popularity'] ?? 0)]);

        return ['q' => $q, 'took_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'hits' => array_slice($hits, 0, $limit), 'engine' => $meili ? 'meilisearch' : 'database'];
    }

    /** @return list<array<string, mixed>> */
    private function meilisearch(string $q, int $limit, bool $billableOnly): array
    {
        $params = ['limit' => max(20, $limit * 2), 'showRankingScore' => true];

        if ($billableOnly) {
            $params['filter'] = 'is_billable = true';                       // leaf codes: doctors never code a category
        }

        $result = $this->client->index($this->indexer->uid(CatalogSearchIndexer::ICD10))->rawSearch($q, $params);

        return array_values($result['hits'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    private function database(string $q, int $limit, bool $billableOnly): array
    {
        $needle = mb_strtolower($q);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle).'%';
        $popularity = (array) config('catalog.search.icd10_popularity', []);

        $rows = DB::connection('catalog')->table('icd10_codes')->where('is_active', true)
            ->when($billableOnly, fn ($q) => $q->where('is_billable', true))
            ->where(function ($w) use ($like, $needle): void {
                $w->whereRaw('lower(code) LIKE ?', [$like])->orWhereRaw('lower(title) LIKE ?', [$like])
                    ->orWhereRaw('EXISTS (SELECT 1 FROM jsonb_array_elements_text(aliases) a WHERE lower(a) LIKE ?)', [$like])
                    ->orWhereRaw('lower(code) = ?', [$needle]);
            })->limit(max(20, $limit * 2))->get();

        $hits = [];

        foreach ($rows as $c) {
            $aliases = json_decode((string) $c->aliases, true) ?: [];
            $score = 0.5;

            if (mb_strtolower($c->code) === $needle || in_array($needle, array_map('mb_strtolower', $aliases), true)) {
                $score = 1.0;
            } elseif (str_starts_with(mb_strtolower($c->code), $needle) || str_starts_with(mb_strtolower($c->title), $needle)) {
                $score = 0.9;
            } elseif (array_filter($aliases, fn ($a) => str_starts_with(mb_strtolower((string) $a), $needle)) !== []) {
                $score = 0.85;
            }

            $hits[] = [
                'id' => $c->code, 'code' => $c->code, 'parent_code' => $c->parent_code, 'chapter' => $c->chapter, 'block' => $c->block,
                'title' => $c->title, 'title_bn' => $c->title_bn, 'aliases' => $aliases, 'is_billable' => (bool) $c->is_billable,
                'popularity' => (int) ($popularity[$c->code] ?? 0), '_rankingScore' => $score,
            ];
        }

        return $hits;
    }
}
