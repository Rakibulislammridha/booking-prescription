<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Data\DrugSearchQuery;
use App\Domain\Catalog\Search\CatalogSearchIndexer;
use App\Domain\Catalog\Search\DrugSearchService;
use App\Domain\Catalog\Search\Icd10SearchService;
use App\Domain\Catalog\Services\CatalogCache;
use Meilisearch\Client;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Real Meilisearch (127.0.0.1:7700) with the per-engineer SCOUT_PREFIX. Builds test{N}_catalog_drugs / _icd10 once for
 * the class and deletes them afterwards. Skipped when Meilisearch is not reachable (CONVENTIONS §6.4).
 */
#[Group('catalog')]
#[Group('search')]
final class SearchIndexTest extends TestCase
{
    private static bool $built = false;

    private static string $host = '';

    private static ?string $key = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'meilisearch']);
        self::$host = (string) config('scout.meilisearch.host');
        self::$key = config('scout.meilisearch.key');

        try {
            app(Client::class)->health();
        } catch (\Throwable) {
            $this->markTestSkipped('Meilisearch is not reachable');
        }

        if (self::$built === false) {
            app(CatalogSearchIndexer::class)->rebuildAll();
            self::$built = true;
        }

        $this->asTenant('a');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$built) {
            $client = new Client(self::$host, self::$key);                    // the app is gone by now: no config() here
            $prefix = (string) getenv('SCOUT_PREFIX');

            foreach ([$prefix.'catalog_drugs', $prefix.'catalog_icd10', $prefix.'catalog_drugs_next', $prefix.'catalog_icd10_next'] as $uid) {
                try {
                    $client->waitForTask($client->deleteIndex($uid)['taskUid'], 60_000, 100);
                } catch (ApiException) {
                }
            }

            self::$built = false;
        }

        parent::tearDownAfterClass();
    }

    public function test_uids_carry_the_prefix_and_settings_are_applied(): void
    {
        $this->assertNotSame('', config('scout.prefix'), 'the test env must set SCOUT_PREFIX=test{N}_');
        $indexer = app(CatalogSearchIndexer::class);
        $this->assertSame(config('scout.prefix').'catalog_drugs', $indexer->uid('catalog_drugs'));

        $settings = app(Client::class)->index($indexer->uid('catalog_drugs'))->getSettings();
        $this->assertSame(config('catalog.search.catalog_drugs.searchableAttributes'), $settings['searchableAttributes']);
        $this->assertSame(config('catalog.search.catalog_drugs.rankingRules'), $settings['rankingRules']);
        $this->assertSame(['napa'], $settings['synonyms']['নাপা']);
        $this->assertSame(['paracetamol'], $settings['synonyms']['acetaminophen']);
        $this->assertContains('is_active', $settings['filterableAttributes']);
        $this->assertEqualsCanonicalizing(['/', '+'], $settings['separatorTokens']);

        $stats = app(Client::class)->index($indexer->uid('catalog_drugs'))->stats();
        $this->assertGreaterThanOrEqual(633 + 60, $stats['numberOfDocuments']);
        $this->assertSame(74, app(Client::class)->index($indexer->uid('catalog_icd10'))->stats()['numberOfDocuments']);

        $uids = array_map(fn ($i) => $i->getUid(), app(Client::class)->getIndexes(new IndexesQuery)->getResults());
        $this->assertNotContains($indexer->uid('catalog_drugs').'_next', $uids, 'rebuild swap leaves no _next index');
    }

    public function test_drug_queries(): void
    {
        $search = app(DrugSearchService::class);

        $nap = $search->search(new DrugSearchQuery(q: 'nap', limit: 8));
        $this->assertSame('meilisearch', $nap->engine);
        $this->assertSame('Napa', $nap->hits[0]['brand_name']);
        $this->assertSame('Napa 500 mg Tab', $nap->hits[0]['label']);

        $this->assertSame('Napa', $search->search(new DrugSearchQuery(q: 'napaa'))->hits[0]['brand_name'], 'typo tolerance');
        $this->assertSame('Napa', $search->search(new DrugSearchQuery(q: 'নাপা'))->hits[0]['brand_name'], 'Bangla alias / synonym');

        $generic = $search->search(new DrugSearchQuery(q: 'paracet', limit: 6));
        $this->assertContains('g'.$generic->hits[0]['generic_id'], collect($generic->hits)->pluck('id')->all(), 'generic row reachable');

        $filtered = $search->search(new DrugSearchQuery(q: 'nap 665'));
        $this->assertSame(['Napa 665 mg XR Tab'], collect($filtered->hits)->where('doc_type', 'presentation')->pluck('label')->all());

        $this->assertNull(collect($search->search(new DrugSearchQuery(q: 'ranitid', limit: 20))->hits)->firstWhere('brand_name', 'Ranitid'), 'inactive documents are filtered');
        $this->assertNotNull(collect($search->search(new DrugSearchQuery(q: 'neotack'))->hits)->firstWhere('brand_name', 'Neotack'));
    }

    public function test_icd10_queries(): void
    {
        $icd = app(Icd10SearchService::class);
        $this->assertSame('E11.9', $icd->search('sugar')['hits'][0]['code']);
        $this->assertSame('E11.9', $icd->search('সুগার')['hits'][0]['code']);
        $this->assertSame('A09', $icd->search('loose motion')['hits'][0]['code']);
        $this->assertSame('I10', $icd->search('pressure')['hits'][0]['code']);
        $this->assertSame('E11_9', $icd->search('sugar')['hits'][0]['id'], 'document ids replace the dot (Meilisearch id rules)');
    }

    public function test_incremental_upsert_and_rebuild_keep_serving(): void
    {
        $indexer = app(CatalogSearchIndexer::class);
        $version = app(CatalogCache::class)->currentVersion();
        $changed = $indexer->changedSince($version);
        $this->assertGreaterThan(600, count($changed['strengths']));

        $indexer->upsertChanged(['strengths' => array_slice($changed['strengths'], 0, 3), 'icd10_codes' => array_slice($changed['icd10_codes'], 0, 2)]);
        $this->assertSame('Napa', app(DrugSearchService::class)->search(new DrugSearchQuery(q: 'napa'))->hits[0]['brand_name']);

        $count = $indexer->rebuild('catalog_icd10');
        $this->assertSame(74, $count);
        $this->assertSame('E11.9', app(Icd10SearchService::class)->search('sugar')['hits'][0]['code']);
    }
}
