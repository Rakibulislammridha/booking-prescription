<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Search;

use App\Domain\Catalog\Data\DrugSearchQuery;
use App\Domain\Catalog\Data\DrugSearchResult;
use App\Models\Tenant\CustomBrand;
use App\Tenancy\Facades\Tenancy;
use Meilisearch\Client;
use Meilisearch\Contracts\FederationOptions;
use Meilisearch\Contracts\MultiSearchFederation;
use Meilisearch\Contracts\SearchQuery;
use Meilisearch\Exceptions\ApiException;

/**
 * Drug autocomplete (PRESCRIPTION.md §3.3): one federated multi-search over catalog_drugs + t{id}_custom_brands,
 * re-ranked in the app with the doctor's usage/favourite signals (DoctorUsageBoostProvider — supplied by the
 * Prescription module). Falls back to Postgres ILIKE when scout.driver is not meilisearch.
 */
final class DrugSearchService
{
    private const SEARCH_ON = ['brand_name', 'generic_name', 'generic_aliases', 'brand_aliases'];

    /** t{id}_custom_brands has no brand_aliases attribute (CATALOG.md §4.2); naming it would be rejected. */
    private const SEARCH_ON_CUSTOM = ['brand_name', 'generic_name', 'generic_aliases'];

    public function __construct(
        private readonly Client $client,
        private readonly CatalogSearchIndexer $indexer,
        private readonly DoctorUsageBoostProvider $boosts,
        private readonly DatabaseDrugSearch $fallback,
    ) {}

    public function search(DrugSearchQuery $query): DrugSearchResult
    {
        $started = hrtime(true);
        [$q, $trailing] = DrugSearchQuery::splitTrailingNumber($query->q);
        $strengthMg = $query->strengthMg ?? $trailing;

        if ($q === '') {
            return new DrugSearchResult($q, 0, []);
        }

        $meili = config('scout.driver') === 'meilisearch';
        $raw = $meili ? $this->federated($q, $strengthMg, $query->limit) : $this->fallback->hits($q, $strengthMg, $query->limit);
        $hits = $this->rank($raw, $query);

        return new DrugSearchResult($q, (int) ((hrtime(true) - $started) / 1_000_000), $hits, $meili ? 'meilisearch' : 'database');
    }

    /** @return list<array<string, mixed>> */
    private function federated(string $q, ?float $strengthMg, int $limit): array
    {
        $filter = ['is_active = true'];

        if ($strengthMg !== null) {
            $filter[] = "(strength_mg = {$strengthMg} OR doc_type = generic)";
        }

        $queries = [$this->query($this->indexer->uid(CatalogSearchIndexer::DRUGS), $q, $filter, self::SEARCH_ON)];
        $tenantUid = Tenancy::check() ? (new CustomBrand)->searchableAs() : null;

        if ($tenantUid !== null) {
            $queries[] = $this->query($tenantUid, $q, $filter, self::SEARCH_ON_CUSTOM);
        }

        $federation = (new MultiSearchFederation)->setLimit(max(40, $limit * 3));

        try {
            $response = $this->client->multiSearch($queries, $federation);
        } catch (ApiException $e) {
            if ($tenantUid === null || $e->errorCode !== 'index_not_found') {
                throw $e;
            }

            $response = $this->client->multiSearch([$queries[0]], $federation);      // tenant index not provisioned yet
        }

        return $response['hits'] ?? [];
    }

    /**
     * @param  list<string>  $filter
     * @param  list<string>  $searchOn
     */
    private function query(string $uid, string $q, array $filter, array $searchOn): SearchQuery
    {
        return (new SearchQuery)->setIndexUid($uid)->setQuery($q)->setFilter($filter)->setShowRankingScore(true)
            ->setAttributesToSearchOn($searchOn)->setFederationOptions((new FederationOptions)->setWeight(1.0));
    }

    /**
     * score = _rankingScore × 1000 + min(use_count, 200) × 2 + (fav_for_dx ? 150 : 0) + (presentation ? 20 : 0);
     * sorted desc, stable on popularity; generic docs keep at least one slot when they scored.
     *
     * @param  list<array<string, mixed>>  $raw
     * @return list<array<string, mixed>>
     */
    private function rank(array $raw, DrugSearchQuery $query): array
    {
        $ids = array_values(array_filter(array_map(fn ($h) => (string) ($h['id'] ?? ''), $raw)));
        $boosts = $query->doctorId !== null && $ids !== [] ? $this->boosts->drugBoosts($query->doctorId, $query->dxCodes, $ids) : [];
        $hits = [];

        foreach ($raw as $doc) {
            $id = (string) ($doc['id'] ?? '');
            $boost = $boosts[$id] ?? ['usage' => 0, 'fav_for_dx' => false, 'last_shorthand' => null];
            $rankingScore = (float) ($doc['_rankingScore'] ?? $doc['_federation']['weightedRankingScore'] ?? 0.5);
            $isPresentation = ($doc['doc_type'] ?? 'presentation') === 'presentation';

            $score = $rankingScore * 1000 + min((int) $boost['usage'], 200) * 2 + ($boost['fav_for_dx'] ? 150 : 0) + ($isPresentation ? 20 : 0);

            unset($doc['_federation'], $doc['_rankingScore'], $doc['_rankingScoreDetails']);

            $hits[] = $doc + ['custom_brand_id' => null, 'brand_id' => null, 'strength_id' => null, 'review_status' => null, 'promoted_to_master' => null]
                + ['usage' => (int) $boost['usage'], 'fav_for_dx' => (bool) $boost['fav_for_dx'], 'score' => round($score, 1), 'last_shorthand' => $boost['last_shorthand']];
        }

        usort($hits, fn ($a, $b) => [$b['score'], (int) ($b['popularity'] ?? 0)] <=> [$a['score'], (int) ($a['popularity'] ?? 0)]);

        $top = array_slice($hits, 0, $query->limit);
        $hasGeneric = array_filter($top, fn ($h) => $h['doc_type'] === 'generic') !== [];

        if (! $hasGeneric && count($hits) > $query->limit) {
            foreach (array_slice($hits, $query->limit) as $candidate) {
                if ($candidate['doc_type'] === 'generic') {
                    $top[count($top) - 1] = $candidate;                 // "prescribe by generic" stays reachable

                    break;
                }
            }
        }

        return $top;
    }
}
